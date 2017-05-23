<?php
/**
 * User: apaoww
 * Date: 4/14/15
 * Time: 7:32 PM
 */
namespace apaoww\DbManagerOci8;

use Yii;
use yii\db\Connection;
use yii\db\Query;
use yii\db\Expression;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\di\Instance;
use yii\rbac\Assignment;
use yii\rbac\BaseManager;
use yii\rbac\Item;
use yii\rbac\Role;
use yii\rbac\Rule;
use yii\rbac\Permission;

/**
 * DbManager represents an authorization manager that stores authorization information in database.
 *
 * The database connection is specified by [[db]]. And the database schema
 * should be as described in "framework/rbac/*.sql". You may change the names of
 * the three tables used to store the authorization data by setting [[itemTable]],
 * [[itemChildTable]] and [[assignmentTable]].
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Alexander Kochetov <creocoder@gmail.com>
 * @extended by Apa Oww <apa.oww@gmail.com>
 * @since 2.0
 */
class DbManager extends BaseManager
{
    /**
     * @var Connection|string the DB connection object or the application component ID of the DB connection.
     * After the DbManager object is created, if you want to change this property, you should only assign it
     * with a DB connection object.
     */
    public $db = 'db';

    /**
     * @var string the name of the table storing authorization items. Defaults to "auth_item".
     */
    public $itemTable = 'auth_item';

    /**
     * @var string the name of the table storing authorization item hierarchy. Defaults to "auth_item_child".
     */
    public $itemChildTable = 'auth_item_child';

    /**
     * @var string the name of the table storing authorization item assignments. Defaults to "auth_assignment".
     */
    public $assignmentTable = 'auth_assignment';

    /**
     * @var string the name of the table storing rules. Defaults to "auth_rule".
     */
    public $ruleTable = 'auth_rule';


    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init()
    {
        parent::init();

        $this->db = Instance::ensure($this->db, Connection::className());
    }

    /**
     * @inheritdoc
     */
    public function checkAccess($userId, $permissionName, $params = [])
    {
        $assignments = $this->getAssignments($userId);
        if (!isset($params['user'])) {
            $params['user'] = $userId;
        }
        return $this->checkAccessRecursive($userId, $permissionName, $params, $assignments);
    }

    /**
     * Performs access check for the specified user.
     * This method is internally called by [[checkAccess()]].
     * @param string|integer $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return boolean whether the operations can be performed by the user.
     */
    protected function checkAccessRecursive($user, $itemName, $params, $assignments)
    {
        if (($item = $this->getItem($itemName)) === null) {
            return false;
        }

        Yii::trace($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($this->defaultRoles[$itemName]) || isset($assignments[$itemName])) {
            return true;
        }

        $query = new Query;
        $parents = $query->select(['PARENT'])
            ->from($this->itemChildTable)
            ->where(['CHILD' => $itemName])
            ->column($this->db);
        foreach ($parents as $parent) {
            if ($this->checkAccessRecursive($user, $parent, $params, $assignments)) {
                return true;
            }
        }


        return false;
    }

    /**
     * @inheritdoc
     */
    protected function getItem($name)
    {
        $row = (new Query)->from($this->itemTable)
            ->where(['NAME' => $name])
            ->one($this->db);

        if ($row === false) {
            return null;
        }

        if (!isset($row['DATA']) || ($data = @unserialize($row['DATA'])) === false) {
            $data = null;
        }

        return $this->populateItem($row);
    }

    /**
     * Returns a value indicating whether the database supports cascading update and delete.
     * The default implementation will return false for SQLite database and true for all other databases.
     * @return boolean whether the database supports cascading update and delete.
     */
    protected function supportsCascadeUpdate()
    {
        return strncmp($this->db->getDriverName(), 'sqlite', 6) !== 0;
    }

    /**
     * @inheritdoc
     */
    protected function addItem($item)
    {
        $time = time();
        if ($item->createdAt === null) {
            $item->createdAt = $time;
        }
        if ($item->updatedAt === null) {
            $item->updatedAt = $time;
        }
        $this->db->createCommand()
            ->insert($this->itemTable, [
                'NAME' => $item->name,
                'TYPE' => $item->type,
                'DESCRIPTION' => $item->description,
                'RULE_NAME' => $item->ruleName,
                'DATA' => $item->data === null ? null : serialize($item->data),
                'CREATED_AT' => $item->createdAt,
                'UPDATED_AT' => $item->updatedAt,
            ])->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeItem($item)
    {
        if (!$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->delete($this->itemChildTable, ['OR', 'PARENT=:name', 'CHILD=:name'], [':name' => $item->name])
                ->execute();
            $this->db->createCommand()
                ->delete($this->assignmentTable, ['ITEM_NAME' => $item->name])
                ->execute();
        }

        $this->db->createCommand()
            ->delete($this->itemTable, ['NAME' => $item->name])
            ->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateItem($name, $item)
    {
        if (!$this->supportsCascadeUpdate() && $item->name !== $name) {
            $this->db->createCommand()
                ->update($this->itemChildTable, ['PARENT' => $item->name], ['PARENT' => $name])
                ->execute();
            $this->db->createCommand()
                ->update($this->itemChildTable, ['CHILD' => $item->name], ['CHILD' => $name])
                ->execute();
            $this->db->createCommand()
                ->update($this->assignmentTable, ['ITEM_NAME' => $item->name], ['ITEM_NAME' => $name])
                ->execute();
        }

        $item->updatedAt = time();

        $this->db->createCommand()
            ->update($this->itemTable, [
                'NAME' => $item->name,
                'DESCRIPTION' => $item->description,
                'RULE_NAME' => $item->ruleName,
                'DATA' => $item->data === null ? null : serialize($item->data),
                'UPDATED_AT' => $item->updatedAt,
            ], [
                'NAME' => $name,
            ])->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function addRule($rule)
    {
        $time = time();
        if ($rule->createdAt === null) {
            $rule->createdAt = $time;
        }
        if ($rule->updatedAt === null) {
            $rule->updatedAt = $time;
        }
        $this->db->createCommand()
            ->insert($this->ruleTable, [
                'NAME' => $rule->name,
                'DATA' => serialize($rule),
                'CREATED_AT' => $rule->createdAt,
                'UPDATED_AT' => $rule->updatedAt,
            ])->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateRule($name, $rule)
    {
        if (!$this->supportsCascadeUpdate() && $rule->name !== $name) {
            $this->db->createCommand()
                ->update($this->itemTable, ['RULE_NAME' => $rule->name], ['RULE_NAME' => $name])
                ->execute();
        }

        $rule->updatedAt = time();

        $this->db->createCommand()
            ->update($this->ruleTable, [
                'NAME' => $rule->name,
                'DATA' => serialize($rule),
                'UPDATED_AT' => $rule->updatedAt,
            ], [
                'NAME' => $name,
            ])->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeRule($rule)
    {
        if (!$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->delete($this->itemTable, ['RULE_NAME' => $rule->name])
                ->execute();
        }

        $this->db->createCommand()
            ->delete($this->ruleTable, ['NAME' => $rule->name])
            ->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function getItems($type)
    {
        $query = (new Query)
            ->from($this->itemTable)
            ->where(['TYPE' => $type]);

        $items = [];
        foreach ($query->all($this->db) as $row) {
            $items[$row['NAME']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * Populates an auth item with the data fetched from database
     * @param array $row the data from the auth item table
     * @return Item the populated auth item instance (either Role or Permission)
     */
    protected function populateItem($row)
    {
        $class = $row['TYPE'] == Item::TYPE_PERMISSION ? Permission::className() : Role::className();

        if (!isset($row['DATA']) || ($data = @unserialize($row['DATA'])) === false) {
            $data = null;
        }

        return new $class([
            'name' => $row['NAME'],
            'type' => $row['TYPE'],
            'description' => (isset($row['DESCRIPTION']) ? $row['DESCRIPTION'] : NULL),
            'ruleName' => (isset($row['RULE_NAME']) ? $row['RULE_NAME'] : NULL),
            'data' => $data,
            'createdAt' => $row['CREATED_AT'],
            'updatedAt' => $row['UPDATED_AT'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getRolesByUser($userId)
    {
        $query = (new Query)->select('"b".*')
            ->from(['a' => $this->assignmentTable, 'b' => $this->itemTable])
            ->where('"a"."ITEM_NAME"="b"."NAME"')
            ->andWhere(['"a"."USER_ID"' => $userId]);

        $roles = [];
        foreach ($query->all($this->db) as $row) {
            $roles[$row['NAME']] = $this->populateItem($row);
        }
        return $roles;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByRole($roleName)
    {
        $childrenList = $this->getChildrenList();
        $result = [];
        $this->getChildrenRecursive($roleName, $childrenList, $result);
        if (empty($result)) {
            return [];
        }
        $query = (new Query)->from($this->itemTable)->where([
            'TYPE' => Item::TYPE_PERMISSION,
            'NAME' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['NAME']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByUser($userId)
    {
        $query = (new Query)->select('ITEM_NAME')
            ->from($this->assignmentTable)
            ->where(['USER_ID' => $userId]);

        $childrenList = $this->getChildrenList();
        $result = [];
        foreach ($query->column($this->db) as $roleName) {
            $this->getChildrenRecursive($roleName, $childrenList, $result);
        }

        if (empty($result)) {
            return [];
        }

        $query = (new Query)->from($this->itemTable)->where([
            'TYPE' => Item::TYPE_PERMISSION,
            'NAME' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['NAME']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * Returns the children for every parent.
     * @return array the children list. Each array key is a parent item name,
     * and the corresponding array value is a list of child item names.
     */
    protected function getChildrenList()
    {
        $query = (new Query)->from($this->itemChildTable);
        $parents = [];
        foreach ($query->all($this->db) as $row) {
            $parents[$row['PARENT']][] = $row['CHILD'];
        }
        return $parents;
    }

    /**
     * Recursively finds all children and grand children of the specified item.
     * @param string $name the name of the item whose children are to be looked for.
     * @param array $childrenList the child list built via [[getChildrenList()]]
     * @param array $result the children and grand children (in array keys)
     */
    protected function getChildrenRecursive($name, $childrenList, &$result)
    {
        if (isset($childrenList[$name])) {
            foreach ($childrenList[$name] as $child) {
                $result[$child] = true;
                $this->getChildrenRecursive($child, $childrenList, $result);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getRule($name)
    {
        $row = (new Query)->select(['DATA'])
            ->from($this->ruleTable)
            ->where(['NAME' => $name])
            ->one($this->db);
        return $row === false ? null : unserialize($row['DATA']);
    }

    /**
     * @inheritdoc
     */
    public function getRules()
    {
        $query = (new Query)->from($this->ruleTable);

        $rules = [];
        foreach ($query->all($this->db) as $row) {
            $rules[$row['NAME']] = unserialize($row['DATA']);
        }

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getAssignment($roleName, $userId)
    {
        $row = (new Query)->from($this->assignmentTable)
            ->where(['USER_ID' => $userId, 'ITEM_NAME' => $roleName])
            ->one($this->db);

        if ($row === false) {
            return null;
        }

        if (!isset($row['DATA']) || ($data = @unserialize($row['DATA'])) === false) {
            $data = null;
        }

        return new Assignment([
            'userId' => $row['USER_ID'],
            'roleName' => $row['ITEM_NAME'],
            'createdAt' => $row['CREATED_AT'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getAssignments($userId)
    {
        $query = (new Query)
            ->from($this->assignmentTable)
            ->where(['USER_ID' => $userId]);

        $assignments = [];
        foreach ($query->all($this->db) as $row) {
            if (!isset($row['DATA']) || ($data = @unserialize($row['DATA'])) === false) {
                $data = null;
            }
            $assignments[$row['ITEM_NAME']] = new Assignment([
                'userId' => $row['USER_ID'],
                'roleName' => $row['ITEM_NAME'],
                'createdAt' => $row['CREATED_AT'],
            ]);
        }

        return $assignments;
    }

    /**
     * @inheritdoc
     */
    public function addChild($parent, $child)
    {
        if ($parent->name === $child->name) {
            throw new InvalidParamException("Cannot add '{$parent->name}' as a child of itself.");
        }

        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidParamException("Cannot add a role as a child of a permission.");
        }

        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        $this->db->createCommand()
            ->insert($this->itemChildTable, ['PARENT' => $parent->name, 'CHILD' => $child->name])
            ->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function removeChild($parent, $child)
    {
        return $this->db->createCommand()
            ->delete($this->itemChildTable, ['PARENT' => $parent->name, 'CHILD' => $child->name])
            ->execute() > 0;
    }

    /**
     * @inheritdoc
     */
    public function getChildren($name)
    {
        $query = (new Query)
            ->select(['NAME', 'TYPE', 'DESCRIPTION', 'RULE_NAME', 'DATA', 'CREATED_AT', 'UPDATED_AT'])
            ->from([$this->itemTable, $this->itemChildTable])
            ->where(['PARENT' => $name, 'NAME' => new Expression('"child"')]);

        $children = [];
        foreach ($query->all($this->db) as $row) {
            $children[$row['NAME']] = $this->populateItem($row);
        }

        return $children;
    }

    /**
     * Checks whether there is a loop in the authorization item hierarchy.
     * @param Item $parent the parent item
     * @param Item $child the child item to be added to the hierarchy
     * @return boolean whether a loop exists
     */
    protected function detectLoop($parent, $child)
    {
        if ($child->name === $parent->name) {
            return true;
        }
        foreach ($this->getChildren($child->name) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function assign($role, $userId, $rule = null, $data = null)
    {
        $assignment = new Assignment([
            'userId' => $userId,
            'roleName' => $role->name,
            'createdAt' => time(),
        ]);

        $this->db->createCommand()
            ->insert($this->assignmentTable, [
                'USER_ID' => $assignment->userId,
                'ITEM_NAME' => $assignment->roleName,
                'CREATED_AT' => $assignment->createdAt,
            ])->execute();

        return $assignment;
    }

    /**
     * @inheritdoc
     */
    public function revoke($role, $userId)
    {
        return $this->db->createCommand()
            ->delete($this->assignmentTable, ['USER_ID' => $userId, 'ITEM_NAME' => $role->name])
            ->execute() > 0;
    }

    /**
     * @inheritdoc
     */
    public function revokeAll($userId)
    {
        return $this->db->createCommand()
            ->delete($this->assignmentTable, ['USER_ID' => $userId])
            ->execute() > 0;
    }

    /**
     * Removes all authorization data.
     */
    public function clearAll()
    {
        $this->clearAssignments();
        $this->db->createCommand()->delete($this->itemChildTable)->execute();
        $this->db->createCommand()->delete($this->itemTable)->execute();
        $this->db->createCommand()->delete($this->ruleTable)->execute();
    }

    /**
     * Removes all authorization assignments.
     */
    public function clearAssignments()
    {
        $this->db->createCommand()->delete($this->assignmentTable)->execute();
    }

    /**
     * Returns a value indicating whether the child already exists for the parent.
     *
     * @param Item $parent
     * @param Item $child
     * @return boolean whether `$child` is already a child of `$parent`
     */
    public function hasChild($parent, $child)
    {
        // TODO: Implement hasChild() method.
    }

    /**
     * Removes all authorization data, including roles, permissions, rules, and assignments.
     */
    public function removeAll()
    {
        // TODO: Implement removeAll() method.
    }

    /**
     * Removes all permissions.
     * All parent child relations will be adjusted accordingly.
     */
    public function removeAllPermissions()
    {
        // TODO: Implement removeAllPermissions() method.
    }

    /**
     * Removes all roles.
     * All parent child relations will be adjusted accordingly.
     */
    public function removeAllRoles()
    {
        // TODO: Implement removeAllRoles() method.
    }

    /**
     * Removes all rules.
     * All roles and permissions which have rules will be adjusted accordingly.
     */
    public function removeAllRules()
    {
        // TODO: Implement removeAllRules() method.
    }

    /**
     * Removes all role assignments.
     */
    public function removeAllAssignments()
    {
        // TODO: Implement removeAllAssignments() method.
    }

    /**
     * Removed all children form their parent.
     * Note, the children items are not deleted. Only the parent-child relationships are removed.
     * @param Item $parent
     * @return boolean whether the removal is successful
     */
    public function removeChildren($parent)
    {
        // TODO: Implement removeChildren() method.
    }

    /**
     * Returns all role assignment information for the specified role.
     * @param string $roleName
     * @return Assignment[] the assignments. An empty array will be
     * returned if role is not assigned to any user.
     * @since 2.0.7
     */
    public function getUserIdsByRole($roleName)
    {
        if (empty($roleName)) {
            return [];
        }

        return (new Query)->select('[[USER_ID]]')
            ->from($this->assignmentTable)
            ->where(['ITEM_NAME' => $roleName])->column($this->db);
    }
    public function canAddChild($parent, $child)
    {
        return !$this->detectLoop($parent, $child);
    }
}
