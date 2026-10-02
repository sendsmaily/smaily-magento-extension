<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Installs the module tables into the test database by translating
 * etc/db_schema.xml into CREATE TABLE DDL directly.
 *
 * Magento's own declarative-schema pipeline needs a fully installed
 * application, so this harness derives the DDL from the same single source
 * of truth instead — columns, primary/unique constraints and indexes stay
 * in lockstep with what setup:upgrade would install (the sandbox
 * setup:upgrade run remains the gate for the declarative pipeline itself).
 */
class SchemaInstaller
{
    public function __construct(
        private readonly AdapterInterface $connection
    ) {
    }

    /**
     * Drop and recreate all module tables plus the core_config_data mirror.
     */
    public function install(string $dbSchemaXmlPath): void
    {
        $this->createCoreConfigData();

        $schema = simplexml_load_file($dbSchemaXmlPath);
        if ($schema === false) {
            throw new \RuntimeException('Unable to parse ' . $dbSchemaXmlPath);
        }

        foreach ($schema->table as $table) {
            $name = (string)$table['name'];
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($name));
            $this->connection->query($this->tableDdl($table));
        }
    }

    /**
     * Minimal mirror of the core configuration table (scope/scope_id/path
     * unique key included — the migration patch depends on it existing).
     */
    private function createCoreConfigData(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `core_config_data`');
        $this->connection->query(
            'CREATE TABLE `core_config_data` ('
            . ' `config_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . " `scope` VARCHAR(8) NOT NULL DEFAULT 'default',"
            . ' `scope_id` INT NOT NULL DEFAULT 0,'
            . " `path` VARCHAR(255) NOT NULL DEFAULT 'general',"
            . ' `value` TEXT NULL,'
            . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (`config_id`),'
            . ' UNIQUE KEY `CORE_CONFIG_DATA_SCOPE_SCOPE_ID_PATH` (`scope`,`scope_id`,`path`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Minimal mirror of the core quote table, for the tests that join or
     * patch it. The legacy reminder_date/is_sent columns are part of the
     * stub because the 2.8.x schema patch exists to drop them, and that
     * patch reads store_id too.
     */
    public function createQuote(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        $this->connection->query(
            'CREATE TABLE `quote` ('
            . ' `entity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `store_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `customer_email` VARCHAR(255) NULL,'
            . ' `reminder_date` TIMESTAMP NULL,'
            . ' `is_sent` SMALLINT NULL,'
            . ' PRIMARY KEY (`entity_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Minimal mirror of the core flag table (FlagManager's storage), for the
     * uninstall tests.
     */
    public function createFlag(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `flag`');
        $this->connection->query(
            'CREATE TABLE `flag` ('
            . ' `flag_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `flag_code` VARCHAR(255) NOT NULL,'
            . ' `state` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `flag_data` MEDIUMTEXT NULL,'
            . ' `last_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (`flag_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Minimal mirror of Magento_AdminNotification's inbox table (where the
     * admin notices land), for the setup notice tests.
     */
    public function createAdminNotificationInbox(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `adminnotification_inbox`');
        $this->connection->query(
            'CREATE TABLE `adminnotification_inbox` ('
            . ' `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `severity` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `date_added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' `title` VARCHAR(255) NOT NULL,'
            . ' `description` TEXT NULL,'
            . ' `url` VARCHAR(255) NULL,'
            . ' `is_read` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `is_remove` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (`notification_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Seed one quote row; $columns adds to or overrides the defaults.
     *
     * @param array<string, mixed> $columns
     */
    public function seedQuote(int $entityId, array $columns = []): void
    {
        $this->connection->insert(
            'quote',
            array_merge(['entity_id' => $entityId, 'store_id' => 1], $columns)
        );
    }

    /**
     * Minimal mirrors of the core customer_entity and newsletter_subscriber
     * tables — only the columns the contact import's audience reads.
     */
    public function createContactTables(): void
    {
        $this->dropContactTables();
        $this->connection->query(
            'CREATE TABLE `customer_entity` ('
            . ' `entity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `website_id` SMALLINT UNSIGNED NULL,'
            . ' `email` VARCHAR(255) NULL,'
            . ' `store_id` SMALLINT UNSIGNED NULL DEFAULT 0,'
            . ' PRIMARY KEY (`entity_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->connection->query(
            'CREATE TABLE `newsletter_subscriber` ('
            . ' `subscriber_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `store_id` SMALLINT UNSIGNED NULL DEFAULT 0,'
            . ' `customer_id` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `subscriber_email` VARCHAR(150) NULL,'
            . ' `subscriber_status` INT NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (`subscriber_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function dropContactTables(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `customer_entity`');
        $this->connection->query('DROP TABLE IF EXISTS `newsletter_subscriber`');
    }

    /**
     * Render one declarative <table> node as CREATE TABLE DDL.
     */
    private function tableDdl(\SimpleXMLElement $table): string
    {
        $parts = [];
        foreach ($table->column as $column) {
            $parts[] = $this->columnDdl($column);
        }
        foreach ($table->constraint as $constraint) {
            $parts[] = $this->constraintDdl($constraint);
        }
        foreach ($table->index as $index) {
            $parts[] = sprintf(
                'KEY `%s` (%s)',
                (string)$index['referenceId'],
                $this->columnList($index)
            );
        }

        return sprintf(
            'CREATE TABLE `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            (string)$table['name'],
            implode(', ', $parts)
        );
    }

    /**
     * Render one declarative <column> node.
     */
    private function columnDdl(\SimpleXMLElement $column): string
    {
        $xsi = $column->attributes('http://www.w3.org/2001/XMLSchema-instance');
        $type = (string)($xsi['type'] ?? '');
        $name = (string)$column['name'];

        $sql = '`' . $name . '` ' . $this->sqlType($type, $column);
        if (in_array($type, ['int', 'smallint'], true) && (string)$column['unsigned'] === 'true') {
            $sql .= ' UNSIGNED';
        }
        $sql .= (string)$column['nullable'] === 'true' ? ' NULL' : ' NOT NULL';
        $sql .= $this->defaultClause($type, $column);
        if ((string)$column['identity'] === 'true') {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }

    /**
     * Map a declarative column type onto MySQL DDL.
     */
    private function sqlType(string $type, \SimpleXMLElement $column): string
    {
        switch ($type) {
            case 'int':
                return 'INT';
            case 'smallint':
                return 'SMALLINT';
            case 'varchar':
                return 'VARCHAR(' . (int)$column['length'] . ')';
            case 'text':
                return 'TEXT';
            case 'mediumtext':
                return 'MEDIUMTEXT';
            case 'timestamp':
                return 'TIMESTAMP';
            case 'boolean':
                return 'TINYINT(1)';
            default:
                throw new \RuntimeException(sprintf(
                    'Unsupported db_schema column type "%s" for column "%s" — extend SchemaInstaller::sqlType()',
                    $type,
                    (string)$column['name']
                ));
        }
    }

    /**
     * DEFAULT (and, for timestamps, ON UPDATE) clause for a column.
     */
    private function defaultClause(string $type, \SimpleXMLElement $column): string
    {
        $default = $column['default'] === null ? null : (string)$column['default'];
        $clause = '';

        if ($type === 'timestamp') {
            if ($default === 'CURRENT_TIMESTAMP') {
                $clause .= ' DEFAULT CURRENT_TIMESTAMP';
            }
            if ((string)$column['on_update'] === 'true') {
                $clause .= ' ON UPDATE CURRENT_TIMESTAMP';
            }

            return $clause;
        }

        if ($default === null) {
            return '';
        }
        if ($type === 'boolean') {
            return ' DEFAULT ' . ($default === 'true' ? '1' : '0');
        }

        return " DEFAULT '" . addslashes($default) . "'";
    }

    /**
     * Render one declarative <constraint> node.
     */
    private function constraintDdl(\SimpleXMLElement $constraint): string
    {
        $xsi = $constraint->attributes('http://www.w3.org/2001/XMLSchema-instance');
        $type = (string)($xsi['type'] ?? '');
        switch ($type) {
            case 'primary':
                return 'PRIMARY KEY (' . $this->columnList($constraint) . ')';
            case 'unique':
                return sprintf(
                    'UNIQUE KEY `%s` (%s)',
                    (string)$constraint['referenceId'],
                    $this->columnList($constraint)
                );
            default:
                throw new \RuntimeException(
                    'Unsupported db_schema constraint type "' . $type . '" — extend SchemaInstaller::constraintDdl()'
                );
        }
    }

    /**
     * Backtick-quoted column list of a constraint/index node.
     */
    private function columnList(\SimpleXMLElement $node): string
    {
        $columns = [];
        foreach ($node->column as $column) {
            $columns[] = '`' . (string)$column['name'] . '`';
        }

        return implode(',', $columns);
    }
}
