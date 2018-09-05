<?php


use yii\base\InvalidConfigException;
use apaoww\oci8\Schema;
use yii\rbac\DbManager;

/**
 * Initializes RBAC tables
 *
 * @author Apa Oww <apa.oww@gmail.com>
 * @since 2.0
 */
class m140506_102106_rbac_init extends \yii\db\Migration
{
    /**
     * @throws yii\base\InvalidConfigException
     * @return DbManager
     */
    protected function getAuthManager()
    {
        $authManager = Yii::$app->getAuthManager();
        if (!$authManager instanceof DbManager) {
            throw new InvalidConfigException('You should configure "authManager" component to use database before executing this migration.');
        }
        return $authManager;
    }

    public function up()
    {
        $authManager = $this->getAuthManager();
        $this->db = $authManager->db;

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable($authManager->ruleTable, [
            'NAME' => Schema::TYPE_STRING . '(64) NOT NULL',
            'DATA' => Schema::TYPE_TEXT,
            'CREATED_AT' => Schema::TYPE_INTEGER,
            'UPDATED_AT' => Schema::TYPE_INTEGER,
            'PRIMARY KEY ("NAME")',
        ], $tableOptions);

        $this->createTable($authManager->itemTable, [
            'NAME' => Schema::TYPE_STRING . '(64) NOT NULL',
            'TYPE' => Schema::TYPE_INTEGER . ' NOT NULL',
            'DESCRIPTION' => Schema::TYPE_TEXT,
            'RULE_NAME' => Schema::TYPE_STRING . '(64)',
            'DATA' => Schema::TYPE_TEXT,
            'CREATED_AT' => Schema::TYPE_INTEGER,
            'UPDATED_AT' => Schema::TYPE_INTEGER,
            'PRIMARY KEY ("NAME")',
            'FOREIGN KEY ("RULE_NAME") REFERENCES ' . $authManager->ruleTable . ' ("NAME") ON DELETE SET NULL',
        ], $tableOptions);
        $this->createIndex('IDX_AUTH_ITEM_TYPE', $authManager->itemTable, 'TYPE');

        $this->createTable($authManager->itemChildTable, [
            'PARENT' => Schema::TYPE_STRING . '(64) NOT NULL',
            'CHILD' => Schema::TYPE_STRING . '(64) NOT NULL',
            'PRIMARY KEY ("PARENT", "CHILD")',
            'FOREIGN KEY ("PARENT") REFERENCES ' . $authManager->itemTable . ' ("NAME") ON DELETE CASCADE',
            'FOREIGN KEY ("CHILD") REFERENCES ' . $authManager->itemTable . ' ("NAME") ON DELETE CASCADE',
        ], $tableOptions);

        $this->createTable($authManager->assignmentTable, [
            'ITEM_NAME' => Schema::TYPE_STRING . '(64) NOT NULL',
            'USER_ID' => Schema::TYPE_STRING . '(64) NOT NULL',
            'CREATED_AT' => Schema::TYPE_INTEGER,
            'PRIMARY KEY ("ITEM_NAME", "USER_ID")',
            'FOREIGN KEY ("ITEM_NAME") REFERENCES ' . $authManager->itemTable . ' ("NAME") ON DELETE CASCADE',
        ], $tableOptions);
    }

    public function down()
    {
        $authManager = $this->getAuthManager();
        $this->db = $authManager->db;

        $this->dropTable($authManager->assignmentTable);
        $this->dropTable($authManager->itemChildTable);
        $this->dropTable($authManager->itemTable);
        $this->dropTable($authManager->ruleTable);
    }
}
