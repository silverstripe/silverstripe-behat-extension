<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Dev\BehatFixtureFactory;
use SilverStripe\Dev\FixtureBlueprint;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\Connect\TempDatabase;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * Context used to create fixtures in the SilverStripe ORM.
 */
class FixtureContext implements Context
{
    use MainContextAwareTrait;

    protected $context;

    /**
     * @var FixtureFactory
     */
    protected $fixtureFactory;

    /**
     * @var String Absolute path where file fixtures are located.
     * These will automatically get copied to their location
     * declare through the 'Given a file "..."' step defition.
     */
    protected $filesPath;

    /**
     * Temp database helper
     *
     * @var TempDatabase
     */
    protected $tempDatabase;

    /**
     * @var string[] Tracks all files and folders created from fixtures, for later cleanup.
     */
    protected $createdFilesPaths = array();

    /**
     * @var string[] Tracks any config files that have been activated as part of a scenario
     */
    protected $activatedConfigFiles = array();

    /**
     * @var string[][] Tracks any extensions that have been added to classes
     */
    protected $addedExtensions = array();

    /**
     * @var array Stores the asset tuples.
     */
    protected $createdAssets = array();

    /**
     * FixtureContext constructor.
     * @param null $filesPath
     */
    public function __construct($filesPath = null)
    {
        if (empty($filesPath)) {
            throw new InvalidArgumentException("filesPath is required");
        }
        $this->setFilesPath($filesPath);
        $this->setTempDatabase(new TempDatabase());
    }

    /**
     * @return FixtureFactory
     */
    public function getFixtureFactory()
    {
        if (!$this->fixtureFactory) {
            $this->fixtureFactory = $this->scaffoldDefaultFixtureFactory();
        }
        return $this->fixtureFactory;
    }


    /**
     * Build default fixture factory
     *
     * @return FixtureFactory
     */
    protected function scaffoldDefaultFixtureFactory()
    {
        $fixtureFactory = Injector::inst()->create(BehatFixtureFactory::class);

        // Register blueprints
        /** @var FixtureBlueprint $blueprint */
        $blueprint = Injector::inst()->create(FixtureBlueprint::class, Member::class);
        $blueprint->addCallback('beforeCreate', function ($identifier, &$data, &$fixtures) {
            if (!isset($data['FirstName'])) {
                $data['FirstName'] = $identifier;
            }
        });
        $fixtureFactory->define(Member::class, $blueprint);
        return $fixtureFactory;
    }

    /**
     * @param FixtureFactory $factory
     */
    public function setFixtureFactory(FixtureFactory $factory)
    {
        $this->fixtureFactory = $factory;
    }

    /**
     * @param String
     */
    public function setFilesPath($path)
    {
        $this->filesPath = $path;
    }

    /**
     * @return String
     */
    public function getFilesPath()
    {
        return $this->filesPath;
    }

    /**
     * @return TempDatabase
     */
    public function getTempDatabase()
    {
        return $this->tempDatabase;
    }

    /**
     * @param TempDatabase $database
     * @return $this
     */
    public function setTempDatabase(TempDatabase $database)
    {
        $this->tempDatabase = $database;
        return $this;
    }

    /**
     * @BeforeScenario @database-defaults
     *
     * @param BeforeScenarioScope $event
     */
    public function beforeDatabaseDefaults(BeforeScenarioScope $event)
    {
        $this->getTempDatabase()->clearAllData();
        DB::get_conn()->quiet();
        $dataClasses = ClassInfo::subclassesFor(DataObject::class);
        array_shift($dataClasses);
        foreach ($dataClasses as $dataClass) {
            \singleton($dataClass)->requireDefaultRecords();
        }
    }

    /**
     * @AfterScenario
     * @param AfterScenarioScope $event
     */
    public function afterResetDatabase(AfterScenarioScope $event)
    {
        $this->getTempDatabase()->clearAllData();
    }

    /**
     * @AfterScenario
     * @param AfterScenarioScope $event
     */
    public function afterResetAssets(AfterScenarioScope $event)
    {
        $store = $this->getAssetStore();
        if (is_array($this->createdAssets)) {
            foreach ($this->createdAssets as $asset) {
                $store->delete($asset['FileFilename'], $asset['FileHash']);
            }
        }
    }

    /**
     * Example: Given a "page" "Page 1"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)"$/
     * @param string $type
     * @param string $id
     */
    public function stepCreateRecord($type, $id)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->prepareFixture($class, $id);
        $this->getFixtureFactory()->createObject($class, $id, $fields);
    }

    /**
     * Example: Given a "page" "Page 1" has the "content" "My content"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" has (?:an|a|the) "(.*)" "(.*)"$/
     * @param string $type
     * @param string $id
     * @param string $field
     * @param string $value
     */
    public function stepCreateRecordHasField($type, $id, $field, $value)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->convertFields(
            $class,
            array($field => $value)
        );
        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->getFixtureFactory()->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->getFixtureFactory()->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: Given a "page" "Page 1" with "URL"="page-1" and "Content"="my page 1"
     * Example: Given the "page" "Page 1" has "URL"="page-1" and "Content"="my page 1"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" (?:with|has) (".*)$/
     * @param string $type
     * @param string $id
     * @param string $data
     */
    public function stepCreateRecordWithData($type, $id, $data)
    {
        $class = $this->convertTypeToClass($type);
        preg_match_all(
            '/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/',
            $data ?? '',
            $matches
        );
        $fields = $this->convertFields(
            $class,
            array_combine($matches['key'] ?? [], $matches['value'] ?? [])
        );
        $fields = $this->prepareFixture($class, $id, $fields);
        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->getFixtureFactory()->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->getFixtureFactory()->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: And the "page" "Page 2" has the following data
     * | Content | <blink> |
     * | My Property | foo |
     * | My Boolean | bar |
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" has the following data$/
     * @param string $type
     * @param string $id
     * @param string $null
     * @param TableNode $fieldsTable
     */
    public function stepCreateRecordWithTable($type, $id, $null, TableNode $fieldsTable)
    {
        $class = $this->convertTypeToClass($type);
        // TODO Support more than one record
        $fields = $this->convertFields($class, $fieldsTable->getRowsHash());
        $fields = $this->prepareFixture($class, $id, $fields);

        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->fixtureFactory->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->fixtureFactory->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: Given the "page" "Page 1.1" is a child of the "page" "Page1".
     * Note that this change is not published by default
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" is a ([^\s]*) of (?:an|a|the) "([^"]+)" "([^"]+)"/
     * @param string $type
     * @param string $id
     * @param string $relation
     * @param string $relationType
     * @param string $relationId
     */
    public function stepUpdateRecordRelation($type, $id, $relation, $relationType, $relationId)
    {
        $class = $this->convertTypeToClass($type);

        $relationClass = $this->convertTypeToClass($relationType);
        $relationObj = $this->fixtureFactory->get($relationClass, $relationId);
        if (!$relationObj) {
            $relationObj = $this->fixtureFactory->createObject($relationClass, $relationId);
        }

        $data = array();
        if ($relation == 'child') {
            $data['ParentID'] = $relationObj->ID;
        }

        $obj = $this->fixtureFactory->get($class, $id);
        if ($obj) {
            $obj->update($data);
            $obj->write();
        } else {
            $obj = $this->fixtureFactory->createObject($class, $id, $data);
        }

        switch ($relation) {
            case 'parent':
                $relationObj->ParentID = $obj->ID;
                $relationObj->write();
                break;
            case 'child':
                // already written through $data above
                break;
            default:
                throw new InvalidArgumentException(sprintf(
                    'Invalid relation "%s"',
                    $relation
                ));
        }
    }

    /**
     * Assign a type of object to another type of object. The base object will be created if it does not exist already.
     * If the last part of the string (in the "X" relation) is omitted, then the first matching relation will be used.
     *
     * @example I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1"
     * @Given /^I assign (?:an|a|the) "([^"]+)" "([^"]+)" to (?:an|a|the) "([^"]+)" "([^"]+)"$/
     * @param string $type
     * @param string $value
     * @param string $relationType
     * @param string $relationId
     */
    public function stepIAssignObjToObj($type, $value, $relationType, $relationId)
    {
        self::stepIAssignObjToObjInTheRelation($type, $value, $relationType, $relationId, null);
    }

    /**
     * Assign a type of object to another type of object. The base object will be created if it does not exist already.
     * If the last part of the string (in the "X" relation) is omitted, then the first matching relation will be used.
     * Assumption: one object has relationship  (has_one, has_many or many_many ) with the other object
     *
     * @example I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1" in the "Terms" relation
     * @Given /^I assign (?:an|a|the) "([^"]+)" "([^"]+)" to (?:an|a|the) "([^"]+)" "([^"]+)" in the "([^"]+)" relation$/
     * @param string $type
     * @param string $value
     * @param string $relationType
     * @param string $relationId
     * @param string $relationName
     * @throws Exception
     */
    public function stepIAssignObjToObjInTheRelation($type, $value, $relationType, $relationId, $relationName)
    {
        $class = $this->convertTypeToClass($type);
        $relationClass = $this->convertTypeToClass($relationType);

        // Check if this fixture object already exists - if not, we create it
        $relationObj = $this->getFixtureFactory()->get($relationClass, $relationId);
        if (!$relationObj) {
            $relationObj = $this->getFixtureFactory()->createObject($relationClass, $relationId);
        }

        // Check if there is relationship defined in many_many (includes belongs_many_many)
        $manyField = null;
        $oneField = null;
        if ($relationObj->manyMany()) {
            $manyField = array_search($class, $relationObj->manyMany() ?? []);
            if ($manyField && strlen($relationName ?? '') > 0) {
                $manyField = $relationName;
            }
        }
        if (empty($manyField) && $relationObj->hasMany(true)) {
            $manyField = array_search($class, $relationObj->hasMany() ?? []);
            if ($manyField && strlen($relationName ?? '') > 0) {
                $manyField = $relationName;
            }
        }
        if (empty($manyField) && $relationObj->hasOne()) {
            $oneField = array_search($class, $relationObj->hasOne() ?? []);
            if ($oneField && strlen($relationName ?? '') > 0) {
                $oneField = $relationName;
            }
        }
        if (empty($manyField) && empty($oneField)) {
            throw new \Exception("'$relationClass' has no relationship (has_one, has_many and many_many) with '$class'!");
        }

        // Get the searchable field to check if the fixture object already exists
        $temObj = new $class;
        if (isset($temObj->Name)) {
            $field = "Name";
        } elseif (isset($temObj->Title)) {
            $field = "Title";
        } else {
            $field = "ID";
        }

        // Check if the fixture object exists - if not, we create it
        $obj = DataObject::get($class)->filter($field, $value)->first();
        if (!$obj) {
            $obj = $this->getFixtureFactory()->createObject($class, $value);
        }
        // If has_many or many_many, add this fixture object to the relation object
        // If has_one, set value to the joint field with this fixture object's ID
        if ($manyField) {
            $relationObj->$manyField()->add($obj);
        } elseif ($oneField) {
            // E.g. $has_one = array('PanelOffer' => 'Offer');
            // then the join field is PanelOfferID. This is the common rule in the CMS
            $relationObj->{$oneField . 'ID'} = $obj->ID;
        }

        $relationObj->write();
    }

     /**
     * Example: Given the "page" "Page 1" is not published
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" is ([^"]*)$/
     * @param string $type
     * @param string $id
     * @param string $state
     */
    public function stepUpdateRecordState($type, $id, $state)
    {
        $class = $this->convertTypeToClass($type);
        /** @var DataObject|Versioned $obj */
        $obj = $this->getFixtureFactory()->get($class, $id);
        if (!$obj) {
            throw new InvalidArgumentException(sprintf(
                'Can not find record "%s" with identifier "%s"',
                $type,
                $id
            ));
        }

        switch ($state) {
            case 'published':
                $obj->copyVersionToStage('Stage', 'Live');
                break;
            case 'not published':
            case 'unpublished':
                $oldMode = Versioned::get_reading_mode();
                Versioned::set_stage(Versioned::LIVE);
                $clone = clone $obj;
                $clone->delete();
                Versioned::set_reading_mode($oldMode);
                break;
            case 'deleted':
                $obj->delete();
                break;
            default:
                throw new InvalidArgumentException(sprintf(
                    'Invalid state: "%s"',
                    $state
                ));
        }
    }

    /**
     * Accepts YAML fixture definitions similar to the ones used in SilverStripe unit testing.
     *
     * Example: Given there are the following member records:
     *  member1:
     *    Email: member1@test.com
     *  member2:
     *    Email: member2@test.com
     *
     * @Given /^there are the following ([^\s]*) records$/
     * @param string $dataObject
     * @param PyStringNode $string
     */
    public function stepThereAreTheFollowingRecords($dataObject, PyStringNode $string)
    {
        $yaml = array_merge(array($dataObject . ':'), $string->getStrings());
        $yaml = implode("\n  ", $yaml);

        // Save fixtures into database
        // TODO Run prepareAsset() for each File and Folder record
        $yamlFixture = new YamlFixture($yaml);
        $yamlFixture->writeInto($this->getFixtureFactory());
    }

    /**
     * Example: Given a "member" "Admin" belonging to "Admin Group"
     *
     * @Given /^(?:an|a|the) "member" "([^"]+)" belonging to "([^"]+)"$/
     * @param string $id
     * @param string $groupId
     */
    public function stepCreateMemberWithGroup($id, $groupId)
    {
        /** @var Group $group */
        $group = $this->getFixtureFactory()->get(Group::class, $groupId);
        if (!$group) {
            $group = $this->getFixtureFactory()->createObject(Group::class, $groupId);
        }

        /** @var Member $member */
        $member = $this->getFixtureFactory()->get(Member::class, $id);
        if (!$member) {
            $member = $this->getFixtureFactory()->createObject(Member::class, $id);
        }
        $member->Groups()->add($group);
    }

    /**
     * Example: Given a "member" "Admin" belonging to "Admin Group" with "Email"="test@test.com"
     *
     * @Given /^(?:an|a|the) "member" "([^"]+)" belonging to "([^"]+)" with (.*)$/
     * @param string $id
     * @param string $groupId
     * @param string $data
     */
    public function stepCreateMemberWithGroupAndData($id, $groupId, $data)
    {
        preg_match_all(
            '/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/',
            $data ?? '',
            $matches
        );
        $fields = $this->convertFields(
            Member::class,
            array_combine($matches['key'] ?? [], $matches['value'] ?? [])
        );

        /** @var Group $group */
        $group = $this->getFixtureFactory()->get(Group::class, $groupId);
        if (!$group) {
            $group = $this->getFixtureFactory()->createObject(Group::class, $groupId);
        }

        /** @var Member $member */
        $member = $this->getFixtureFactory()->get(Member::class, $id);
        if (!$member) {
            $member = $this->getFixtureFactory()->createObject(Member::class, $id, $fields);
        }
        $member->Groups()->add($group);
    }

    /**
     * Example: Given a "group" "Admin" with permissions "Access to 'Pages' section" and "Access to 'Files' section"
     *
     * @Given /^(?:an|a|the) "group" "([^"]+)" (?:with|has) permissions (.*)$/
     * @param string $id
     * @param string $permissionStr
     */
    public function stepCreateGroupWithPermissions($id, $permissionStr)
    {
        // Convert natural language permissions to codes
        preg_match_all('/"([^"]+)"/', $permissionStr ?? '', $matches);
        $permissions = $matches[1];
        $codes = Permission::get_codes(false);

        $group = $this->getFixtureFactory()->get(Group::class, $id);
        if (!$group) {
            $group = $this->getFixtureFactory()->createObject(Group::class, $id);
        }

        foreach ($permissions as $permission) {
            $found = false;
            foreach ($codes as $code => $details) {
                if ($permission == $code
                    || $permission == $details['name']
                ) {
                    Permission::grant($group->ID, $code);
                    $found = true;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException(sprintf(
                    'No permission found for "%s"',
                    $permission
                ));
            }
        }
    }

    /**
     * Navigates to a record based on its identifier set during fixture creation,
     * using its RelativeLink() method to map the record to a URL.
     * Example: Given I go to the "page" "My Page"
     *
     * @Given /^I go to (?:an|a|the) "([^"]+)" "([^"]+)"/
     * @param string $type
     * @param string $id
     */
    public function stepGoToNamedRecord($type, $id)
    {
        $class = $this->convertTypeToClass($type);
        $record = $this->getFixtureFactory()->get($class, $id);
        if (!$record) {
            throw new InvalidArgumentException(sprintf(
                'Cannot resolve reference "%s", no matching fixture found',
                $id
            ));
        }
        if (!$record->hasMethod('RelativeLink')) {
            throw new InvalidArgumentException('URL for record cannot be determined, missing RelativeLink() method');
        }
        $link = call_user_func([$record, 'RelativeLink']);

        $this->getMainContext()->getSession()->visit($this->getMainContext()->locatePath($link));
    }

    /**
     * @param $extension
     * @param $class
     *
     * @Given I add an extension :extension to the :class class
     */
    public function iAddAnExtensionToTheClass($extension, $class)
    {
        // Validate the extension
        Assert::assertTrue(
            class_exists($extension ?? '') && is_subclass_of($extension, Extension::class),
            'Given extension does not extend Extension'
        );

        // Add the extension to the CLI context
        /** @var Extensible $targetClass */
        $targetClass = $this->convertTypeToClass($class);
        $targetClass::add_extension($extension);
        if (!array_key_exists($targetClass, $this->addedExtensions)) {
            $this->addedExtensions[$targetClass] = [];
        }
        $this->addedExtensions[$targetClass][] = $extension;

        // Write config for this extension too...
        $snakedExtension = strtolower(str_replace('\\', '-', $extension ?? '') ?? '');
        $config = <<<YAML
---
Name: testonly-enable-extension-$snakedExtension
---
$class:
  extensions:
    - $extension
YAML;

        $filename = 'enable-' . $snakedExtension . '.yml';
        $destPath = $this->getDestinationConfigFolder($filename);
        file_put_contents($destPath ?? '', $config);

        // Remember to cleanup...
        $this->activatedConfigFiles[] = $destPath;

        // Flush website. We'll need to dev/build too if it's a DataExtension
        if (is_subclass_of($extension, DataExtension::class)) {
            $this->getMainContext()->visit('/dev/build?flush');
        } else {
            $this->getMainContext()->visit('/?flush');
        }
    }

    /**
     * Get the destination folder for config and assert the given file name doesn't exist within in.
     *
     * @param $filename
     * @return string
     */
    protected function getDestinationConfigFolder($filename)
    {
        $project = ModuleManifest::config()->get('project') ?: 'mysite';
        $mysite = ModuleLoader::getModule($project);
        Assert::assertNotNull($mysite, 'Project exists');
        $destPath = $mysite->getResource("_config/{$filename}")->getPath();
        Assert::assertFileDoesNotExist($destPath, "Config file {$filename} hasn't aleady been loaded");
        return $destPath;
    }

    /**
     * Checks that a file or folder exists in the webroot.
     * Example: There should be a file "assets/Uploads/test.jpg"
     *
     * @Then /^there should be a ((file|folder) )"([^"]*)"/
     * @param string $type
     * @param string $path
     */
    public function stepThereShouldBeAFileOrFolder($type, $path)
    {
        Assert::assertFileExists($this->joinPaths(BASE_PATH, $path));
    }

    /**
     * Checks that a file exists in the asset store with a given filename and hash
     *
     * Example: there should be a filename "Uploads/test.jpg" with hash "59de0c841f"
     *
     * @Then /^there should be a filename "([^"]*)" with hash "([a-fA-Z0-9]+)"/
     * @param string $filename
     * @param string $hash
     */
    public function stepThereShouldBeAFileWithTuple($filename, $hash)
    {
        $exists = $this->getAssetStore()->exists($filename, $hash);
        Assert::assertTrue((bool)$exists, "A file exists with filename $filename and hash $hash");
    }

    /**
     * Replaces fixture references in values with their respective database IDs,
     * with the notation "=><class>.<identifier>". Example: "=>Page.My Page".
     *
     * @Transform /^([^"]+)$/
     * @param string $string
     * @return mixed
     */
    public function lookupFixtureReference($string)
    {
        if (preg_match('/^=>/', $string ?? '')) {
            list($className, $identifier) = explode('.', preg_replace('/^=>/', '', $string ?? '') ?? '', 2);
            $id = $this->getFixtureFactory()->getId($className, $identifier);
            if (!$id) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot resolve reference "%s", no matching fixture found',
                    $string
                ));
            }
            return $id;
        } else {
            return $string;
        }
    }

    /**
     * @Given /^(?:an|a|the) "([^"]*)" "([^"]*)" was (created|last edited) "([^"]*)"$/
     * @param string $type
     * @param string $id
     * @param string $mod
     * @param string $time
     */
    public function aRecordWasLastEditedRelative($type, $id, $mod, $time)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->prepareFixture($class, $id);
        $record = $this->getFixtureFactory()->createObject($class, $id, $fields);
        $date = date("Y-m-d H:i:s", strtotime($time ?? ''));
        $table = $record->baseTable();
        $field = ($mod == 'created') ? 'Created' : 'LastEdited';
        DB::prepared_query(
            "UPDATE \"{$table}\" SET \"{$field}\" = ? WHERE \"ID\" = ?",
            [$date, $record->ID]
        );
        // Support for Versioned extension, by checking for a "Live" stage
        if (DB::get_schema()->hasTable($table . '_Live')) {
            DB::prepared_query(
                "UPDATE \"{$table}_Live\" SET \"{$field}\" = ? WHERE \"ID\" = ?",
                [$date, $record->ID]
            );
        }
    }

    /**
     * Clean up all config files after scenario
     *
     * @AfterScenario
     * @param AfterScenarioScope $event
     */
    public function afterResetConfig(AfterScenarioScope $event)
    {
        $this->clearConfigFiles();
        $this->clearExtensions();
        // Flush
        $this->getMainContext()->visit('/?flush');
    }

    /**
     * Prepares a fixture for use
     *
     * @param string $class
     * @param string $identifier
     * @param array $data
     * @return array Prepared $data with additional injected fields
     */
    protected function prepareFixture($class, $identifier, $data = array())
    {
        if ($class == 'SilverStripe\\Assets\\File' || is_subclass_of($class, 'SilverStripe\\Assets\\File')) {
            $data =  $this->prepareAsset($class, $identifier, $data);
        }
        return $data;
    }

    protected function prepareAsset($class, $identifier, $data = null)
    {
        if (!$data) {
            $data = array();
        }


        $relativeTargetPath = (isset($data['Filename'])) ? $data['Filename'] : $identifier;
        $relativeTargetPath = preg_replace('/^' . ASSETS_DIR . '\/?/', '', $relativeTargetPath ?? '');
        $sourcePath = $this->joinPaths($this->getFilesPath(), basename($relativeTargetPath ?? ''));

        // Create file or folder on filesystem
        if ($class == 'SilverStripe\\Assets\\Folder' || is_subclass_of($class, 'SilverStripe\\Assets\\Folder')) {
            $parent = Folder::find_or_make($relativeTargetPath);
            $data['ID'] = $parent->ID;
        } else {
            // Check file exists
            if (!file_exists($sourcePath ?? '')) {
                throw new InvalidArgumentException(sprintf(
                    'Source file for "%s" cannot be found in "%s"',
                    $relativeTargetPath,
                    $sourcePath
                ));
            }

            // Get parent
            $parentID = 0;
            if (strstr($relativeTargetPath ?? '', '/')) {
                $folderName = dirname($relativeTargetPath ?? '');
                $parent = Folder::find_or_make($folderName);
                if ($parent) {
                    $parentID = $parent->ID;
                }
            }
            $data['ParentID'] = $parentID;

            // Load file into APL and retrieve tuple
            $asset = $this->getAssetStore()->setFromLocalFile(
                $sourcePath,
                $relativeTargetPath,
                null,
                null,
                array(
                    'conflict' => AssetStore::CONFLICT_OVERWRITE,
                    'visibility' => AssetStore::VISIBILITY_PUBLIC
                )
            );
            $data['FileFilename'] = $asset['Filename'];
            $data['FileHash'] = $asset['Hash'];
            $data['FileVariant'] = $asset['Variant'];
        }
        if (!isset($data['Name'])) {
            $data['Name'] = basename($relativeTargetPath ?? '');
        }

        // Save assets
        if (isset($data['FileFilename'])) {
            $this->createdAssets[] = $data;
        }

        return $data;
    }

    /**
     * @return AssetStore
     */
    protected function getAssetStore()
    {
        return Injector::inst()->get(AssetStore::class);
    }

    /**
     * Converts a natural language class description to an actual class name.
     * Respects {@link DataObject::$singular_name} variations.
     * Example: "redirector page" -> "RedirectorPage"
     *
     * @param String
     * @return String Class name
     */
    protected function convertTypeToClass($type)
    {
        $type = trim($type ?? '');

        // Try direct mapping
        $class = str_replace(' ', '', ucwords($type ?? ''));
        if (class_exists($class ?? '') && is_subclass_of($class, DataObject::class)) {
            return ClassInfo::class_name($class);
        }

        // Fall back to singular names
        foreach (array_values(ClassInfo::subclassesFor(DataObject::class) ?? []) as $candidate) {
            if (class_exists($candidate ?? '') && strcasecmp(singleton($candidate)->singular_name() ?? '', $type ?? '') === 0) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Class "%s" does not exist, or is not a subclass of DataObjet',
            $class
        ));
    }

    /**
     * Updates an object with values, resolving aliases set through
     * {@link DataObject->fieldLabels()}.
     *
     * @param string $class Class name
     * @param array $fields Map of field names or aliases to their values.
     * @return array Map of actual object properties to their values.
     */
    protected function convertFields($class, $fields)
    {
        $labels = singleton($class)->fieldLabels();
        foreach ($fields as $fieldName => $fieldVal) {
            if ($fieldLabelKey = array_search($fieldName, $labels ?? [])) {
                unset($fields[$fieldName]);
                $fields[$labels[$fieldLabelKey]] = $fieldVal;
            }
        }
        return $fields;
    }

    protected function joinPaths()
    {
        $args = func_get_args();
        $paths = array();
        foreach ($args as $arg) {
            $paths = array_merge($paths, (array)$arg);
        }
        foreach ($paths as &$path) {
            $path = trim($path ?? '', '/');
        }
        if (substr($args[0] ?? '', 0, 1) == '/') {
            $paths[0] = '/' . $paths[0];
        }
        return join('/', $paths);
    }

    protected function clearExtensions()
    {
        foreach ($this->addedExtensions as $targetClass => $extensions) {
            foreach ($extensions as $extension) {
                /** @var Extensible $targetClass */
                $targetClass::remove_extension($extension);
            }
        }
        $this->addedExtensions = [];
    }

    protected function clearConfigFiles()
    {
        // No files to cleanup
        if (empty($this->activatedConfigFiles)) {
            return;
        }

        foreach ($this->activatedConfigFiles as $configFile) {
            if (file_exists($configFile ?? '')) {
                unlink($configFile ?? '');
            }
        }
        $this->activatedConfigFiles = [];
    }

    /**
     * Catch situations where failed scenarios and early exiting would prevent cleanup.
     */
    public function __destruct()
    {
        $this->clearConfigFiles();
    }
}
