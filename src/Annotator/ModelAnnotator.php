<?php

namespace IdeHelper\Annotator;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Database\Schema\TableSchema;
use Cake\ORM\AssociationCollection;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Exception;
use IdeHelper\Annotation\AnnotationFactory;
use IdeHelper\Annotation\MixinAnnotation;
use IdeHelper\Utility\AppPath;
use ReflectionClass;
use RuntimeException;
use Throwable;

class ModelAnnotator extends AbstractAnnotator {

	const CLASS_TABLE = Table::class;

	/**
	 * @var array
	 */
	protected $_cache = [];

	/**
	 * @param string $path Path to file.
	 * @return bool
	 */
	public function annotate($path) {
		$className = pathinfo($path, PATHINFO_FILENAME);
		if ($className === 'Table' || substr($className, -5) !== 'Table') {
			return false;
		}

		$modelName = substr($className, 0, -5);
		$plugin = $this->getConfig(static::CONFIG_PLUGIN);

		$tableName = $plugin ? ($plugin . '.' . $modelName) : $modelName;
		$tableClass = App::className($tableName, 'Model/Table', 'Table');

		if ($this->_isAbstract($tableClass)) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping table and entity: Abstract class');
			}

			return false;
		}

		$tableReflection = new ReflectionClass($tableClass);
		if (!$tableReflection->isInstantiable()) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping table and entity: Not instantiable');
			}

			return false;
		}

		try {
			$table = TableRegistry::getTableLocator()->get($tableName);
			$schema = $table->getSchema();
			$behaviors = $this->_getBehaviors($table);
		} catch (Exception $e) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping table and entity: ' . $e->getMessage());
			}
			return false;
		} catch (Throwable $e) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping table and entity: ' . $e->getMessage());
			}
			return false;
		}

		$tableAssociations = [];
		try {
			$tableAssociations = $table->associations();
			$associations = $this->_getAssociations($tableAssociations);
		} catch (Exception $e) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping associations: ' . $e->getMessage());
			}
			$associations = [];
		} catch (Throwable $e) {
			if ($this->getConfig(static::CONFIG_VERBOSE)) {
				$this->_io->warn('   Skipping associations: ' . $e->getMessage());
			}
			$associations = [];
		}

		$entityClassName = $table->getEntityClass();
		$entityName = substr($entityClassName, strrpos($entityClassName, '\\') + 1);

		$resTable = $this->_table($path, $entityName, $associations, $behaviors, $table);
		$resEntity = $this->_entity($entityClassName, $entityName, $schema, $tableAssociations);

		return $resTable || $resEntity;
	}

	/**
	 * @param string $path
	 * @param string $entityName
	 * @param array $associations
	 * @param string[] $behaviors
	 * @param \Cake\ORM\Table $table
	 *
	 * @return bool
	 */
	protected function _table($path, $entityName, array $associations, array $behaviors, Table $table) {
		$content = file_get_contents($path);

		$behaviors += $this->_parseLoadedBehaviors($content);
		$annotations = $this->_buildAnnotations($associations, $entityName, $behaviors, $table);

		return $this->_annotate($path, $content, $annotations);
	}

	/**
	 * @param array $associations
	 * @param string $entity
	 * @param string[] $behaviors
	 * @param \Cake\ORM\Table $table
	 *
	 * @return \IdeHelper\Annotation\AbstractAnnotation[]
	 */
	protected function _buildAnnotations(array $associations, $entity, array $behaviors, Table $table) {
		$namespace = $this->getConfig(static::CONFIG_NAMESPACE);
		$annotations = [];
		foreach ($associations as $type => $assocs) {
			foreach ($assocs as $name => $className) {
				$annotations[] = "@property \\{$className}&\\{$type} \${$name}";
			}
		}

		$fullClassName = "{$namespace}\\Model\\Entity\\{$entity}";
		if (class_exists($fullClassName)) {
			if ($table instanceof \Shim\Model\Table\Table) {
				$annotations[] = "@method \\{$fullClassName} newEmptyEntity()";
			}

			// Copied from Bake plugin's DocBlockHelper
			$annotations[] = "@method \\{$fullClassName} get(\$primaryKey, \$options = [])";
			$annotations[] = "@method \\{$fullClassName} newEntity(\$data = null, array \$options = [])";
			$annotations[] = "@method \\{$fullClassName}[] newEntities(array \$data, array \$options = [])";
			$annotations[] = "@method \\{$fullClassName}|false save(\\Cake\\Datasource\\EntityInterface \$entity, \$options = [])";
			$annotations[] = "@method \\{$fullClassName} saveOrFail(\\Cake\\Datasource\\EntityInterface \$entity, \$options = [])";
			$annotations[] = "@method \\{$fullClassName} patchEntity(\\Cake\\Datasource\\EntityInterface \$entity, array \$data, array \$options = [])";
			$annotations[] = "@method \\{$fullClassName}[] patchEntities(\$entities, array \$data, array \$options = [])";
			$annotations[] = "@method \\{$fullClassName} findOrCreate(\$search, callable \$callback = null, \$options = [])";
			$annotations[] = "@method \\{$namespace}\\Model\\Entity\\{$entity}[]|\Cake\Datasource\ResultSetInterface|false saveMany(\$entities, \$options = [])";
		}

		if (version_compare(Configure::version(), '3.9.0') >= 0) {
			$annotations[] = "@method \\{$namespace}\\Model\\Entity\\{$entity}[]|\Cake\Datasource\ResultSetInterface saveManyOrFail(\$entities, \$options = [])";

			$annotations[] = "@method \\{$namespace}\\Model\\Entity\\{$entity}[]|\Cake\Datasource\ResultSetInterface|false deleteMany(\$entities, \$options = [])";
			$annotations[] = "@method \\{$namespace}\\Model\\Entity\\{$entity}[]|\Cake\Datasource\ResultSetInterface deleteManyOrFail(\$entities, \$options = [])";
		}

		// Make replacable via parsed object
		foreach ($annotations as $key => $annotation) {
			$annotationObject = AnnotationFactory::createFromString($annotation);
			if (!$annotationObject) {
				throw new RuntimeException('Cannot factorize annotation ' . $annotation);
			}

			$annotations[$key] = $annotationObject;
		}

		foreach ($behaviors as $behavior) {
			$className = App::className($behavior, 'Model/Behavior', 'Behavior');
			if (!$className) {
				$className = App::className($behavior, 'ORM/Behavior', 'Behavior');
			}
			if (!$className) {
				continue;
			}

			$annotations[] = AnnotationFactory::createOrFail(MixinAnnotation::TAG, "\\{$className}");
		}

		return $annotations;
	}

	/**
	 * @param string $entityClass
	 * @param string $entityName
	 * @param \Cake\Database\Schema\TableSchema $schema
	 * @param \Cake\ORM\AssociationCollection $associations
	 *
	 * @return bool|null
	 */
	protected function _entity($entityClass, $entityName, TableSchema $schema, AssociationCollection $associations) {
		$plugin = $this->getConfig(static::CONFIG_PLUGIN);
		$entityPaths = AppPath::get('Model/Entity', $plugin);
		$entityPath = null;
		while ($entityPaths) {
			$pathTmp = array_shift($entityPaths);
			$pathTmp = str_replace('\\', DS, $pathTmp);
			if (file_exists($pathTmp . $entityName . '.php')) {
				$entityPath = $pathTmp . $entityName . '.php';
				break;
			}
		}
		if (!$entityPath) {
			return null;
		}

		$file = pathinfo($entityPath, PATHINFO_BASENAME);
		$this->_io->verbose('   ' . $file);

		$annotator = $this->getEntityAnnotator($entityClass, $schema, $associations);
		$annotator->annotate($entityPath);

		return true;
	}

	/**
	 * @param string $content
	 * @return string[]
	 */
	protected function _parseLoadedBehaviors($content) {
		preg_match_all('/\$this-\>addBehavior\(\'([a-z.\/]+)\'/i', $content, $matches);
		if (empty($matches[1])) {
			return [];
		}

		$behaviors = array_unique($matches[1]);

		$result = [];
		foreach ($behaviors as $behavior) {
			list (, $behaviorName) = pluginSplit($behavior);
			$result[$behaviorName] = $behavior;
		}

		return $result;
	}

	/**
	 * @param \Cake\ORM\AssociationCollection $tableAssociations
	 * @return array
	 */
	protected function _getAssociations(AssociationCollection $tableAssociations) {
		$associations = [];
		foreach ($tableAssociations->keys() as $key) {
			$association = $tableAssociations->get($key);
			if (!$association) {
				continue;
			}
			$type = get_class($association);

			list(, $name) = pluginSplit($association->getAlias());
			$table = $association->getClassName() ?: $association->getAlias();
			$className = App::className($table, 'Model/Table', 'Table') ?: static::CLASS_TABLE;

			$associations[$type][$name] = $className;

			if ($type !== BelongsToMany::class) {
				continue;
			}

			/** @var \Cake\ORM\Association\BelongsToMany $association */
			$through = $this->throughAlias($association);
			if (!$through) {
				continue;
			}

			$className = App::className($through, 'Model/Table', 'Table') ?: static::CLASS_TABLE;
			list(, $throughName) = pluginSplit($through);
			$type = HasMany::class;
			if (isset($associations[$type][$throughName])) {
				continue;
			}

			$associations[$type][$throughName] = $className;
		}

		return $associations;
	}

	/**
	 * @param \Cake\ORM\Association\BelongsToMany $association
	 * @return string
	 */
	protected function throughAlias(BelongsToMany $association) {
		$through = $association->getThrough();
		if ($through) {
			if (is_object($through)) {
				return $through->getAlias();
			}

			return $through;
		}

		$tableName = $this->_junctionTableName($association);
		$through = Inflector::camelize($tableName);

		return $through;
	}

	/**
	 * @uses \Cake\ORM\Association\BelongsToMany::_junctionTableName()
	 *
	 * @param \Cake\ORM\Association\BelongsToMany $association
	 * @return string
	 */
	protected function _junctionTableName(BelongsToMany $association) {
		$tablesNames = array_map('Cake\Utility\Inflector::underscore', [
			$association->getSource()->getTable(),
			$association->getTarget()->getTable(),
		]);

		sort($tablesNames);

		return implode('_', $tablesNames);
	}

	/**
	 * @param \Cake\ORM\Table $table
	 * @return string[]
	 */
	protected function _getBehaviors($table) {
		$object = $table->behaviors();
		$map = $this->_invokeProperty($object, '_loaded');

		$behaviors = $this->_extractBehaviors($map);

		/** @var string|false $parentClass */
		$parentClass = get_parent_class($table);
		if (!$parentClass) {
			return [];
		}

		if (isset($this->_cache[$parentClass])) {
			$parentBehaviors = $this->_cache[$parentClass];
		} else {
			$parentReflection = new ReflectionClass($parentClass);
			if (!$parentReflection->isInstantiable()) {
				return $behaviors;
			}

			/** @var \Cake\ORM\Table $parent */
			$parent = new $parentClass();

			$object = $parent->behaviors();
			$map = $this->_invokeProperty($object, '_loaded');
			$this->_cache[$parentClass] = $parentBehaviors = $this->_extractBehaviors($map);
		}

		$result = array_diff_key($behaviors, $parentBehaviors);

		return $result;
	}

	/**
	 * @param string[] $map
	 * @return string[]
	 */
	protected function _extractBehaviors(array $map) {
		$result = [];
		foreach ($map as $name => $behavior) {
			$behaviorClassName = get_class($behavior);
			$pluginName = $this->_resolvePluginName($behaviorClassName, $name);
			if ($pluginName === null) {
				continue;
			}
			if ($pluginName) {
				$pluginName .= '.';
			}
			$result[$name] = $pluginName . $name;
		}

		return $result;
	}

	/**
	 * @param string $className
	 * @param string $name
	 *
	 * @return string|null
	 */
	protected function _resolvePluginName($className, $name) {
		if (strpos($className, 'Cake\\ORM') === 0) {
			return '';
		}
		if (strpos($className, 'App\\Model\\') === 0) {
			return '';
		}

		preg_match('#^(.+)\\\\Model\\\\Behavior\\\\' . $name . 'Behavior$#', $className, $matches);
		if (!$matches) {
			return null;
		}

		return str_replace('\\', '/', $matches[1]);
	}

	/**
	 * @param string $entityClass
	 * @param \Cake\Database\Schema\TableSchema $schema
	 * @param \Cake\ORM\AssociationCollection $associations
	 * @return \IdeHelper\Annotator\AbstractAnnotator
	 */
	protected function getEntityAnnotator($entityClass, TableSchema $schema, AssociationCollection $associations) {
		$class = EntityAnnotator::class;
		$tasks = (array)Configure::read('IdeHelper.annotators');
		if (isset($tasks[$class])) {
			$class = $tasks[$class];
		}

		return new $class($this->_io, ['class' => $entityClass, 'schema' => $schema, 'associations' => $associations] + $this->getConfig());
	}

}
