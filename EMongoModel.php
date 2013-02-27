<?php

/**
 *
 */
class EMongoModel extends CModel{

	/**
	 * @var EMongoClient the default database connection for all active record classes.
	 * By default, this is the 'mongodb' application component.
	 * @see getDbConnection
	 */
	public static $db;

	private $_errors=array();	// attribute name => array of errors

	private $_attributes = array();
	private $_related = array();

	/**
	 * (non-PHPdoc)
	 * @see yii/framework/CComponent::__get()
	 */
	public function __get($name){

		$getter='get'.$name;
		if(method_exists($this,$getter))
			return $this->$getter();
		elseif(isset($this->_related[$name])){
			return $this->_related[$name];
		}elseif(array_key_exists($name, $this->relations())){
			return $this->_related[$name]=$this->getRelated($name);
		}elseif(isset($this->_attributes[$name])){
			return $this->_attributes[$name];
		}else{
			return null;
			//return parent::__get($name);
		}

	}

	/**
	 * (non-PHPdoc)
	 * @see CComponent::__set()
	 */
	public function __set($name,$value){
		$setter='set'.$name;
		if(method_exists($this,$setter))
			return $this->$setter($value);
		elseif(isset($this->_related[$name]) || array_key_exists($name, $this->relations()))
			$this->_related[$name]=$value;
		elseif($this->setAttribute($name,$value)===false) // It should never be false, this is a white lie
			parent::__set($name,$value);
	}

	/**
	 * (non-PHPdoc)
	 * @see CComponent::__isset()
	 */
	public function __isset($name){

		if(isset($this->_attributes[$name]))
			return true;
		elseif(isset($this->_related[$name]))
			return true;
		elseif(array_key_exists($name, $this->relations()))
			return $this->getRelated($name)!==null;
		else
			return parent::__isset($name);

	}

	/**
	 * (non-PHPdoc)
	 * @see CComponent::__unset()
	 */
	public function __unset($name){

		if(isset($this->_attributes[$name]))
			unset($this->_attributes[$name]);
		elseif(isset($this->_related[$name]))
			unset($this->_related[$name]);
		else
			parent::__unset($name);

	}

	/**
	 * (non-PHPdoc)
	 * @see CComponent::__call()
	 */
	public function __call($name,$parameters)
	{
		if(array_key_exists($name, $this->relations()))
		{
			if(empty($parameters))
				return $this->getRelated($name,false);
			else
				return $this->getRelated($name,false,$parameters[0]);
		}

		return parent::__call($name,$parameters);
	}

	/**
	 * This sets up our model.
	 * Apart from what Yii normally does this also sets a field cache for reflection so that we only ever do reflection once to
	 * understand what fields are in our model.
	 * @param string $scenario
	 */
	function __construct($scenario = 'insert'){

		if($scenario===null) // internally used by populateRecord() and model()
			return;

		$this->setScenario($scenario);

		// Run reflection and cache it if not already there
		if(!$this->getDbConnection()->getObjCache(get_class($this)) && get_class($this) != 'EMongoModel' /* We can't cache the model */){
			$virtualFields = array();
			$documentFields = array();

			$reflect = new \ReflectionClass(get_class($this));
			$class_vars = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED); // Pre-defined doc attributes

			foreach ($class_vars as $prop) {

				if($prop->isStatic())
					continue;

				$docBlock = $prop->getDocComment();

				// If it is not public and it is not marked as virtual then assume it is document field
				if($prop->isProtected() || preg_match('/@virtual/i', $docBlock) <= 0){
					$documentFields[] = $prop->getName();
				}else{
					$virtualFields[] = $prop->getName();
				}
			}
			$this->getDbConnection()->setObjectCache(get_class($this),
				sizeof($virtualFields) > 0 ? $virtualFields : null,
				sizeof($documentFields) > 0 ? $documentFields : null
			);
		}

		$this->init();

		$this->attachBehaviors($this->behaviors());
		$this->afterConstruct();
	}

	/**
	 * Initializes this model.
	 * This method is invoked when an AR instance is newly created and has
	 * its {@link scenario} set.
	 * You may override this method to provide code that is needed to initialize the model (e.g. setting
	 * initial property values.)
	 */
	public function init(){ return true; }

	/**
	 * (non-PHPdoc)
	 * @see CModel::attributeNames()
	 */
	function attributeNames(){

		$fields = $this->getDbConnection()->getFieldObjCache(get_class($this));
		$virtuals = $this->getDbConnection()->getVirtualObjCache(get_class($this));

		$cols = array_merge(is_array($fields) ? $fields : array(), is_array($virtuals) ? $virtuals : array(), array_keys($this->_attributes));
		return $cols!==null ? $cols : array();
	}

	/**
	 * Holds all our relations
	 * @return array
	 */
	function relations(){ return array(); }

	/**
	 * Finds out if a document attributes actually exists
	 * @param string $name
	 */
	public function hasAttribute($name)
	{
		$attrs = $this->_attributes;
		$fields = $this->getDbConnection()->getFieldObjCache(get_class($this));
		return isset($attrs[$name])||isset($fields[$name])||property_exists($this, $name)?true:false;
	}

	/**
	 * Sets the attribute of the model
	 * @param string $name
	 * @param mixed $value
	 */
	public function setAttribute($name,$value){

		if(property_exists($this,$name))
			$this->$name=$value;
		else//if(isset($this->_attributes[$name]))
			$this->_attributes[$name]=$value;
		//else return false;
		return true;

	}

	/**
	 * Gets a document attribute
	 * @param string $name
	 */
	public function getAttribute($name){
		if(property_exists($this,$name))
			return $this->$name;
		elseif(isset($this->_attributes[$name]))
			return $this->_attributes[$name];
	}

	/**
	 * (non-PHPdoc)
	 * @see CModel::getAttributes()
	 */
	public function getAttributes($names=true)
	{
		$attributes=$this->_attributes;
		$fields = $this->getDbConnection()->getFieldObjCache(get_class($this));

		if(is_array($fields)){
			foreach($fields as $name){
				$attributes[$name] = $this->$name;
			}
		}

		if(is_array($names))
		{
			$attrs=array();
			foreach($names as $name)
			{
				if(property_exists($this,$name))
					$attrs[$name]=$this->$name;
				else
					$attrs[$name]=isset($attributes[$name])?$attributes[$name]:null;
			}
			return $attrs;
		}
		else
			return $attributes;
	}

	/**
	 * Sets the attribute values in a massive way.
	 * @param array $values attribute values (name=>value) to be set.
	 * @param boolean $safeOnly whether the assignments should only be done to the safe attributes.
	 * A safe attribute is one that is associated with a validation rule in the current {@link scenario}.
	 * @see getSafeAttributeNames
	 * @see attributeNames
	 */
	public function setAttributes($values,$safeOnly=true)
	{
		if(!is_array($values))
			return;
		$attributes=array_flip($safeOnly ? $this->getSafeAttributeNames() : $this->attributeNames());
		foreach($values as $name=>$value)
		{
			if($safeOnly){
				if(isset($attributes[$name]))
					$this->$name=$value;
				elseif($safeOnly)
					$this->onUnsafeAttribute($name,$value);
			}else
				$this->$name=$value;
		}
	}

	/**
	 * Sets the attributes to be null.
	 * @param array $names list of attributes to be set null. If this parameter is not given,
	 * all attributes as specified by {@link attributeNames} will have their values unset.
	 * @since 1.1.3
	 */
	public function unsetAttributes($names=null)
	{
		if($names===null)
			$names=$this->attributeNames();
		foreach($names as $name)
			$this->$name=null;
	}

	/**
	 * Returns the related record(s).
	 * This method will return the related record(s) of the current record.
	 * If the relation is 'one' it will return a single object
	 * or null if the object does not exist.
	 * If the relation is 'many' it will return an array of objects
	 * or an empty iterator.
	 * @param string $name the relation name (see {@link relations})
	 * @param boolean $refresh whether to reload the related objects from database. Defaults to false.
	 * @param mixed $params array with additional parameters that customize the query conditions as specified in the relation declaration.
	 * @return mixed the related object(s).
	 * @throws EMongoException if the relation is not specified in {@link relations}.
	 */
	public function getRelated($name,$refresh=false,$params=array())
	{
		if(!$refresh && $params===array() && (isset($this->_related[$name]) || array_key_exists($name,$this->_related)))
			return $this->_related[$name];

		$relations = $this->relations();

		if(!isset($relations[$name]))
			throw new EMongoException(Yii::t('yii','{class} does not have relation "{name}".',
				array('{class}'=>get_class($this), '{name}'=>$name)));

		Yii::trace('lazy loading '.get_class($this).'.'.$name,'extensions.MongoYii.EMongoModel');

		// I am unsure as to the purpose of this bit
		//if($this->getIsNewRecord() && !$refresh && ($relation instanceof CHasOneRelation || $relation instanceof CHasManyRelation))
			//return $relation instanceof CHasOneRelation ? null : array();

		$cursor = array();
		$relation = $relations[$name];

		// Let's get the parts of the relation to understand it entirety of its context
		$cname = $relation[1];
		$fkey = $relation[2];
		$pk = isset($relation['on']) ? $this->{$relation['on']} : $this->{$this->primaryKey()};

		// Form the where clause
		$where = array();
		if(isset($relation['where'])) $where = array_merge($relation['where'], $params);

		// Find out what the pk is and what kind of condition I should apply to it
		if(is_array($pk)){

			// It is an array of _ids
			$clause = array_merge($where, array($fkey=>array('$in' => $pk)));
		}elseif($pk instanceof MongoDBRef){

			// If it is a DBRef I can only get one doc so I should probably just return it here
			// otherwise I will continue on
			$row = $pk::get();
			if(isset($row['_id'])){
				$o = $cname::model();
				$o->populateRecord($row);
				return $o;
			}
			return null;

		}else{

			// It is just one _id
			$clause = array_merge($where, array($fkey=>$pk));
		}

		$o = $cname::model();
		if($relation[0]==='one'){

			// Lets find it and return it
			$cursor = $o->findOne($clause);
		}elseif($relation[0]==='many'){

			// Lets find them and return them
			$cursor = $o->find($clause);
		}
		return $cursor;
	}

	/**
	 * Returns a value indicating whether the named related object(s) has been loaded.
	 * @param string $name the relation name
	 * @return boolean a value indicating whether the named related object(s) has been loaded.
	 */
	public function hasRelated($name)
	{
		return isset($this->_related[$name]) || array_key_exists($name,$this->_related);
	}

	/**
	 * Sets the errors for that particular attribute
	 * @param string $attribute
	 * @param array $errors
	 */
	function setAttributeErrors($attribute, $errors){
		$this->_errors[$attribute]=$errors;
	}

	/* THESE ERROR FUNCTIONS ARE ONLY HERE BECAUSE OF THE WAY IN WHICH PHP RESOLVES THE THE SCOPES OF VARS */
	// I needed to add the error handling function above but I had to include these as well

	/**
	 * Returns a value indicating whether there is any validation error.
	 * @param string $attribute attribute name. Use null to check all attributes.
	 * @return boolean whether there is any error.
	 */
	public function hasErrors($attribute=null)
	{
		if($attribute===null)
			return $this->_errors!==array();
		else
			return isset($this->_errors[$attribute]);
	}

	/**
	 * Returns the errors for all attribute or a single attribute.
	 * @param string $attribute attribute name. Use null to retrieve errors for all attributes.
	 * @return array errors for all attributes or the specified attribute. Empty array is returned if no error.
	 */
	public function getErrors($attribute=null)
	{
		if($attribute===null)
			return $this->_errors;
		else
			return isset($this->_errors[$attribute]) ? $this->_errors[$attribute] : array();
	}

	/**
	 * Returns the first error of the specified attribute.
	 * @param string $attribute attribute name.
	 * @return string the error message. Null is returned if no error.
	 */
	public function getError($attribute)
	{
		return isset($this->_errors[$attribute]) ? reset($this->_errors[$attribute]) : null;
	}

	/**
	 * Adds a new error to the specified attribute.
	 * @param string $attribute attribute name
	 * @param string $error new error message
	 */
	public function addError($attribute,$error)
	{
		$this->_errors[$attribute][]=$error;
	}

	/**
	 * Adds a list of errors.
	 * @param array $errors a list of errors. The array keys must be attribute names.
	 * The array values should be error messages. If an attribute has multiple errors,
	 * these errors must be given in terms of an array.
	 * You may use the result of {@link getErrors} as the value for this parameter.
	 */
	public function addErrors($errors)
	{
		foreach($errors as $attribute=>$error)
		{
			if(is_array($error))
			{
				foreach($error as $e)
					$this->addError($attribute, $e);
			}
			else
				$this->addError($attribute, $error);
		}
	}

	/**
	 * Removes errors for all attributes or a single attribute.
	 * @param string $attribute attribute name. Use null to remove errors for all attribute.
	 */
	public function clearErrors($attribute=null)
	{
		if($attribute===null)
			$this->_errors=array();
		else
			unset($this->_errors[$attribute]);
	}

	/**
	 * Returns the database connection used by active record.
	 * By default, the "mongodb" application component is used as the database connection.
	 * You may override this method if you want to use a different database connection.
	 * @return EMongoClient the database connection used by active record.
	 */
	public function getDbConnection()
	{
		if(self::$db!==null)
			return self::$db;
		else
		{
			self::$db=Yii::app()->mongodb;
			if(self::$db instanceof EMongoClient)
				return self::$db;
			else
				throw new EMongoException(Yii::t('yii','MongoDB Active Record requires a "mongodb" EMongoClient application component.'));
		}
	}

	/**
	 * Cleans or rather resets the document
	 */
    public function clean(){
    	$this->_attributes=array();
		$this->_related=array();

		// blank class properties
		$cache = $this->getDbConnection()->getObjCache(get_class($this));

		if(isset($cache['document'])){
			foreach($cache['document'] as $field)
				$this->$field = null;
		}

		if(isset($cache['virtual'])){
			foreach($cache['virtual'] as $field)
				$this->$field = null;
		}
		return true;
    }

	/**
	 * Gets the formed document with MongoYii objects included
	 */
	function getDocument(){

		$attributes = $this->getDbConnection()->getFieldObjCache(get_class($this));
		$doc = array();

		if(is_array($attributes)){
			foreach($attributes as $field) $doc[$field] = $this->$field;
		}
		return array_merge($doc, $this->_attributes);
	}

	/**
	 * Gets the raw document with MongoYii objects taken out
	 */
	function getRawDocument(){
		return $this->filterRawDocument($this->getDocument());
	}

	/**
	 * Filters a provided document to take out MongoYii objects.
	 * @param array $doc
	 */
	function filterRawDocument($doc){
		if(is_array($doc)){
			foreach($doc as $k => $v){
				if(is_array($v)){
					$doc[$k] = $this->{__FUNCTION__}($doc[$k]);
				}elseif($v instanceof EMongoModel || $v instanceof EMongoDocument){
					$doc[$k] = $doc[$k]->getRawDocument();
				}
			}
		}
		return $doc;
	}

	/**
	 * Gets the JSON encoded document
	 */
	function getJSONDocument(){
		return json_encode($this->getRawDocument());
	}

	/**
	 * Gets the BSON encoded document (never normally needed)
	 */
	function getBSONDocument(){
		return bson_encode($this->getRawDocument());
	}
}
