<?php

declare(strict_types=1);

namespace Extrameile\EmFormRepeatablesave\Form\Finisher;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException;
use TYPO3\CMS\Form\Domain\Model\FormElements\FormElementInterface;

/**
 * SaveRepeatableToDatabase finisher for EXT:form for saving forms which use for example the repeatable_form_elements extension
 * Some source is taken from TYPO3 core form extension.
 * @see /typo3_src/typo3/sysext/form/Classes/Domain/Finishers/SaveToDatabaseFinisher.php
 */
class SaveRepeatableToDatabaseFinisher extends \TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher
{

    /**
     * @var array<mixed>
     */
    protected $defaultOptions = [
        'table' => null,
        'mode' => 'insert',
        'whereClause' => [],
        'elements' => [],
        'databaseColumnMappings' => [],
        'repeat' => false,
    ];

    /**
     * @var \TYPO3\CMS\Core\Database\Connection
     */
    protected $databaseConnection = null;

    /**
     * Executes this finisher
     * @see AbstractFinisher::execute()
     *
     * @return string|null
     * @throws FinisherException
     */
    protected function executeInternal()
    {
        $options = [];
        if (isset($this->options['table'])) {
            $options[] = $this->options;
        } else {
            $options = $this->options;
        }

        foreach ($options as $optionKey => $option) {
            $this->options = $option;
            $this->process($optionKey);
        }

        return null;
    }

    /**
     * Prepare data for saving to database
     *
     * @param array<array> $elementsConfiguration
     * @param array<string, string> $databaseData
     * @param string $prefix
     * @param array<string> $values
     * @return mixed
     */
    protected function prepareData(array $elementsConfiguration, array $databaseData, $prefix = '', $values = [])
    {
        if (\count($values) === 0) {
            $values = $this->getFormValues();
        }
        foreach ($values as $elementIdentifier => $elementValue) {
            if ($this->ignoreElement($elementsConfiguration, $prefix, $elementIdentifier, $elementValue)) {
                continue;
            }

            if ($elementValue instanceof FileReference) {
                if (isset($elementsConfiguration[$elementIdentifier]['saveFileIdentifierInsteadOfUid'])) {
                    $saveFileIdentifierInsteadOfUid = (bool)$elementsConfiguration[$elementIdentifier]['saveFileIdentifierInsteadOfUid'];
                } else {
                    $saveFileIdentifierInsteadOfUid = false;
                }

                if ($saveFileIdentifierInsteadOfUid) {
                    $elementValue = $elementValue->getOriginalResource()->getCombinedIdentifier();
                } else {
                    $elementValue = $elementValue->getOriginalResource()->getProperty('uid_local');
                }
            } elseif (\is_array($elementValue)) {
                $elementValue = \implode(',', $elementValue);
            } elseif ($elementValue instanceof \DateTimeInterface) {
                $format = $elementsConfiguration[$elementIdentifier]['dateFormat'] ?? 'U';
                $elementValue = $elementValue->format($format);
            }

            $databaseData[$elementsConfiguration[$elementIdentifier]['mapOnDatabaseColumn']] = $elementValue;
        }
        return $databaseData;
    }

    /**
     * Check if an element should be ignored while processing
     *
     * @param array<array> $elementsConfiguration
     * @param string $prefix
     * @param string $elementIdentifier
     * @param mixed $elementValue
     */
    protected function ignoreElement(array $elementsConfiguration, $prefix, $elementIdentifier, $elementValue): bool
    {
        if (
            ($elementValue === null || $elementValue === '')
            && isset($elementsConfiguration[$elementIdentifier])
            && isset($elementsConfiguration[$elementIdentifier]['skipIfValueIsEmpty'])
            && $elementsConfiguration[$elementIdentifier]['skipIfValueIsEmpty'] === true
        ) {
            return true;
        }

        $element = $this->getElementByIdentifier($prefix . $elementIdentifier);
        if (
            (!$element instanceof FormElementInterface && !StringUtility::beginsWith($elementIdentifier, '__'))
            || !isset($elementsConfiguration[$elementIdentifier])
            || !isset($elementsConfiguration[$elementIdentifier]['mapOnDatabaseColumn'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * Perform the current database operation
     *
     * @param int $iterationCount
     * @throws FinisherException
     */
    protected function process(int $iterationCount): void
    {
        $this->throwExceptionOnInconsistentConfiguration();

        $table = $this->parseOption('table');
        $table = is_string($table) ? $table : '';
        $elementsConfiguration = $this->parseOption('elements');
        $elementsConfiguration = is_array($elementsConfiguration) ? $elementsConfiguration : [];
        $databaseColumnMappingsConfiguration = $this->parseOption('databaseColumnMappings');
        $repeat = $this->parseOption('repeat');
        $repeat = is_string($repeat) ? $repeat : '';

        $this->databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);

        $databaseData = [];
        foreach ($databaseColumnMappingsConfiguration as $databaseColumnName => $databaseColumnConfiguration) {
            $value = $this->parseOption('databaseColumnMappings.' . $databaseColumnName . '.value');
            if (
                empty($value)
                && $databaseColumnConfiguration['skipIfValueIsEmpty'] === true
            ) {
                continue;
            }

            $databaseData[$databaseColumnName] = $value;
        }

        if ($repeat) {
            $this->processRepeat($repeat, $elementsConfiguration, $databaseData, $table, $iterationCount);
        } else {
            $databaseData = $this->prepareData($elementsConfiguration, $databaseData);

            $this->saveToDatabase($databaseData, $table, $iterationCount);
        }
    }

    /**
     * processes data repeatedly
     *
     * @param string $repeat Container which is repeatable
     * @param array<array> $elementsConfiguration
     * @param array<string, string> $databaseData
     * @param string $table
     * @param int $iterationCount
     */
    protected function processRepeat($repeat, $elementsConfiguration, $databaseData, $table, $iterationCount): void
    {
        $values = $this->getFormValues();
        // container does not exist
        if (!isset($values[$repeat])) {
            $this->throwExceptionOnInconsistentConfiguration();
        }
        $count = \count($values[$repeat]);
        for ($i = 0; $i < $count; $i++) {
            $databaseData = $this->prepareData($elementsConfiguration, $databaseData, $repeat . '.' . $i . '.', $values[$repeat][$i]);
            $this->saveToDatabase($databaseData, $table, $iterationCount, $i);
        }

        $this->finisherContext->getFinisherVariableProvider()->add(
            $this->shortFinisherIdentifier,
            'countInserts.' . $iterationCount,
            $count
        );
    }

    /**
     * Save or insert the values from
     * $databaseData into the table $table
     *
     * @param array<string, string>  $databaseData
     * @param string $table
     * @param int $iterationCount
     * @param int|null $repeatCount
     */
    protected function saveToDatabase(array $databaseData, string $table, int $iterationCount, ?int $repeatCount = null): void
    {
        if (!empty($databaseData)) {
            if ($this->options['mode'] === 'update') {
                $whereClause = $this->options['whereClause'];
                foreach ($whereClause as $columnName => $columnValue) {
                    $whereClause[$columnName] = $this->parseOption('whereClause.' . $columnName);
                }
                $this->databaseConnection->update(
                    $table,
                    $databaseData,
                    $whereClause
                );
            } else {
                $this->databaseConnection->insert($table, $databaseData);
                $insertedUid = (int)$this->databaseConnection->lastInsertId($table);
                $this->finisherContext->getFinisherVariableProvider()->add(
                    $this->shortFinisherIdentifier,
                    'insertedUids.' . $iterationCount . ($repeatCount !== null ? '.' . $repeatCount : ''),
                    $insertedUid
                );
            }
        }
    }

    /**
     * Throws an exception if some inconsistent configuration
     * are detected.
     *
     * @throws FinisherException
     */
    protected function throwExceptionOnInconsistentConfiguration(): void
    {
        if (
            $this->options['mode'] === 'update'
            && empty($this->options['whereClause'])
        ) {
            throw new FinisherException(
                'An empty option "whereClause" is not allowed in update mode.',
                1480469086
            );
        }
    }

    /**
     * Returns the values of the submitted form
     *
     * @return array<mixed>
     */
    protected function getFormValues(): array
    {
        return $this->finisherContext->getFormValues();
    }

    /**
     * Returns a form element object for a given identifier.
     *
     * @param string $elementIdentifier
     */
    protected function getElementByIdentifier(string $elementIdentifier): ?FormElementInterface
    {
        return $this
            ->finisherContext
            ->getFormRuntime()
            ->getFormDefinition()
            ->getElementByIdentifier($elementIdentifier);
    }
}
