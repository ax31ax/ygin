<?php

abstract class DaActiveRecord extends BaseActiveRecord {

  private $__object = null;

  private $_idParent = -1;
  private $_idInstance = -1;
  private $_pkBeforeSave = null;
  
  protected $idObject = null;

  public final function getIdObject() {
    if ($this->idObject == null && $this->__object != null) $this->idObject = $this->__object->id_object;
    if ($this->idObject == null)
      throw new ErrorException('Для модели '.get_class($this).' не установлен id_object');
    return $this->idObject;
  }
  public final function getIdInstance($cache=true) {
    if ($cache && $this->_idInstance != -1) return $this->_idInstance;
    $this->_idInstance = null;
    $object = $this->getObjectInstance();
    $field = $object->getFieldByType(DataType::PRIMARY_KEY);
    if ($field != null) {
      $this->_idInstance = $this->$field;
    }
    return $this->_idInstance;
  }
  public final function setIdInstance($idInstance) {
    $object = $this->getObjectInstance();
    $field = $object->getFieldByType(DataType::PRIMARY_KEY);
    if ($field != null) {
      $this->_idInstance = $idInstance;
      $this->$field = $idInstance;
    } else {
      throw new ErrorException('Не удалось определить первичный ключ объект.');
    }
  }
  public function getIdParent() {
    if ($this->_idParent != -1) return $this->_idParent;
    $this->_idParent = null;
    $object = $this->getObjectInstance();
    $field = $object->getFieldByType(DataType::ID_PARENT);
    if ($field != null) {
      $this->_idParent = $this->$field;
    }
    return $this->_idParent;
  }

  public function getCountChild($parentField=null) {
    $array = $this->getCountChildOfInstances(array($this), $parentField);
    return $array[$this->getIdInstance()];
  }
  public function getCountChildOfInstances($arrayOfModel, $parentField=null) {
    $collection = null;
    if (is_array($arrayOfModel)) {
      $collection = new DaActiveRecordCollection($arrayOfModel);
    } else if ($arrayOfModel instanceof DaActiveRecordCollection) {
      $collection = $arrayOfModel;
    } else {
      throw new ErrorException('Неверно определен тип параметра метода arrayOfModel.');
    }
    if ($collection->getCount() == 0) return $arrayOfModel;
    if ($parentField == null) {
      $parentField = $this->__object->getFieldByType(DataType::ID_PARENT);
    }
    $arrayOfId = $collection->getKeys();
    $countData = array_fill_keys($arrayOfId, 0);
    if ($parentField == null) return $countData;
    $whereConfig = array('in', $parentField, $arrayOfId);
    $data = Yii::app()->db->createCommand()
        ->select($parentField.' AS id, count(*) AS cnt')
        ->from($this->tableName())
        ->where($whereConfig)
        ->group($parentField)
        ->queryAll();
    foreach ($data AS $row) {
      $countData[$row['id']] = $row['cnt'];
    }
    return $countData;
  }

  public function getDependentData($all=true) {
    return $this->getInstancesDependentData($this, $all);
  }
  public function getInstancesDependentData($instances, $all=true) {
    $scalar = false;
    $collection = null;
    if (is_array($instances)) {
      $collection = new DaActiveRecordCollection($instances);
    } else if ($instances instanceof DaActiveRecordCollection) {
      $collection = $instances;
    } else if ($instances instanceof DaActiveRecord) {
      $collection = new DaActiveRecordCollection(array($instances));
      $scalar = true;
    } else {
      throw new ErrorException('Неверно определен тип параметра метода getInstancesDependentData.');
    }

    $arrayOfId = $collection->getKeys();
    $relationParams = $this->getObjectInstance()->relationParameters;
    $assocData = array_fill_keys($arrayOfId, array());
    foreach($relationParams AS $param) {
      $whereConfig = array('and', array('in', $param->getFieldName(), $arrayOfId));

      $idObject = $param->getIdObjectParameter();
      $object = DaObject::getById($idObject, false);

      $data = Yii::app()->db->createCommand()
        ->select($param->getFieldName().' AS id, count(*) AS cnt')
        ->from($object->table_name)
        ->where($whereConfig)
        ->group($param->getFieldName())
        ->queryAll();

    /*
    // многообъектая поддержка
    $iq = new InstanceQuery($where);
    $arrayOfIdObject = Object::getCommonObjectBySingle($idObjectTmp);
    if (count($arrayOfIdObject) > 1) {
      $iq->setUsedObjects(array($idObjectTmp));
    }*/

      foreach ($data AS $row) {
        $assocData[$row['id']][$idObject] = $row['cnt'];
        if (!$all && isset($arrayOfId[$row['id']])) {
          unset($arrayOfId[$row['id']]);
        }
      }
      if (!$all && count($arrayOfId) == 0) break;
    }
    if ($scalar) {
      return array_shift($assocData);
    }
    return $assocData;
  }

  public function isAvailableForDelete($handlerClassCheck = true) {
    $result = $this->isInstancesAvailableForDelete(array($this), $handlerClassCheck);
    return $result[$this->getIdInstance()];
  }
  public function isInstancesAvailableForDelete($instances, $handlerClassCheck = true) {
    $collection = null;
    if (is_array($instances)) {
      $collection = new DaActiveRecordCollection($instances);
    } else {
      $collection = $instances;
    }
    if ($collection->getCount() == 0) return $instances;

    $arrayOfId = $collection->getKeys();
    $resultData = array_fill_keys($arrayOfId, array('result' => true, 'info' => null));

    if ($handlerClassCheck) {
      if ($this->isProcessDeleteChild() === true) {
        return $resultData;  // удалять точно можем, в классе есть обработка
      }
    }
    $countData = $this->getCountChildOfInstances($collection);
    foreach ($countData AS $id => $count) {
      if ($count > 0) {
        $resultData[$id]['result'] = false;
        $resultData[$id]['info'] = array('Существуют дочерние экземпляры в количестве: '.$count);
        $collection->remove($id);
      }
    }

    $dependentData = $this->getInstancesDependentData($collection);
    foreach($dependentData AS $idInstance => $info) {
      if (count($info) > 0) {
        $resultData[$idInstance]['result'] = false;
        $resultData[$idInstance]['info'] = array();
        foreach($info AS $idObject => $count) {
          $obj = DaObject::getById($idObject, false);
          $message = $obj->getName()." (связей: ".$count.")";
          $resultData[$idInstance]['info'][] = $message;
        }
      }
    }
    return $resultData;
  }

  /**
   * Показывает системе, определена ли обработка удаления зависимых данных для объекта.
   * По умолчанию, в случае если на экземпляр объекта есть ссылка в других объектах, такой экземпляр удалить нельзя.
   * Если данный метод возвращает true, то подразумевается, что обработка таких данных предусмотренна разработчиком (например, в методе beforeDelete).
   * @return boolean
   */
  public function isProcessDeleteChild() {
    return false;
  }


  public function getInstanceCaption() {
    $object = $this->getObjectInstance();
    $attr = $object->field_caption;
    if ($attr == null) {
      throw new ErrorException('Для объекта '.$object->name.' (ид='.$object->id_object.') не установлено свойство field_caption');
    }
    return $this->$attr;
  }

  public final function setObjectInstance(DaObject $object) {
    $this->__object = $object;
  }

  /**
   * @return DaObject
   */
  public final function getObjectInstance() {
    if ($this->__object == null) {
      $this->__object = DaObject::getById($this->getIdObject(), false);
    }
    return $this->__object;
  }

  /**
   * @param integer $idInstance
   * @return DaActiveRecord
   */
  public function findByIdInstance($idInstance) {
    $field = $this->getObjectInstance()->getFieldByType(DataType::PRIMARY_KEY);
    return $this->findByAttributes(array($field=>$idInstance));
  }

  public static function getInstanceOfArrayById(Array $arrayOfInstance, $id) {
    // только если первичный ключ не составной. Если составной, то надо дописать.
    $c = count($arrayOfInstance);
    for ($i = 0; $i < $c; $i++) {
      $instance = $arrayOfInstance[$i];
      if ($instance->getPrimaryKey() == $id) {
        return $instance;
      }
    }
    return null;
  }

  public function findAllByPkArray(array $arrayOfPk) {
    $cr = new CDbCriteria();
    $pkName = ($this->__object != null ? $this->getInstanceKeyName() : null);
    if ($pkName == null) {
      $pkName = $this->getPKName();
      if (is_array($pkName)) $pkName = $this->getInstanceKeyName();
    }
    $cr->addInCondition($pkName, $arrayOfPk);
    return $this->findAll($cr);
  }
  
  public function getPKName() {
    $table = $this->getMetaData()->tableSchema;
    return $table->primaryKey;
  }
  public function getInstanceKeyName() {
    // TODO черновая версия
    if ($this->__object != null) {
      return $this->__object->getFieldByType(DataType::PRIMARY_KEY);
    }
    return null;
  }


  public function getDir($makeDir=true, $absolute=false) {
    $ob = $this->getObjectInstance();
    $folder = $ob->getFolderName();
    if ($folder != null) {
      $folder = HFile::addSlashPath($folder);
    } else if ($makeDir) {
      $err = "Незадана папка для хранения файлов объекта ".$ob->getName()." (id_object=".$ob->getIdObject().")";
      throw new ErrorException($err);
    }

    $instanceFolder = $this->getIdInstance();

    /*
     $idDomain = $this->getIdDomainInstance();
     if ($idDomain != null) {
      $domain = EngineDomain::loadByIdDomain($idDomain);
      if ($domain != null) {
        $domainPath = $domain->getPath2File();
        if ($domainPath != null) {
          $folder = $domainPath."/".$folder;
        }
      } else { // bad
        echo "Ошибка определения домена";
        exit;
      }
    }*/

    $pathToFile = $folder.$instanceFolder.'/';
    $webroot = Yii::getPathOfAlias('webroot').'/';
    if ($makeDir) {
      if (!file_exists($webroot.$pathToFile)) {
        umask(0);
        mkdir($webroot.$pathToFile, 0777, true);
      }
    }
    if ($absolute) $pathToFile = $webroot.$pathToFile;
    return $pathToFile;
  }

  
  protected function beforeDelete() {
    $idObject = $this->getIdObject();
    //$idInstance = $this->getPrimaryKey();

    // Проверяем есть ли у данного экземпляра зависимые от него экземпляры (например, если данный экземпляр ялвяется родительским для других)
    if (!$this->isAvailableForDelete(false)) {
      return false;
    }

    // обрабатываем файлы экземпляра
    if ($idObject != File::model()->getIdObject()) {
      // TODO
      Yii::app()->db->createCommand('DELETE FROM da_search_data WHERE id_object=:obj AND id_instance=:inst')->execute(array(':obj'=>$idObject, ':inst'=>$this->getIdInstance()));

      $files = File::model()->byInstance($this)->findAll();
      foreach ($files AS $f)
        $f->delete();
    }

    // обрабатываем пхп-скрипты экземпляра
    $scriptsField = $this->getObjectInstance()->getFieldByType(DataType::PHP_SCRIPT, true);
    foreach($scriptsField AS $field) {
      $idPhpScript = $this->$field;
      if ($idPhpScript == null) continue;
      $phpScript = PhpScriptInstance::model()->findByAttributes(array('id_php_script'=>$idPhpScript) );
      if ($phpScript != null) {
        // Перед удалением проверяем, используется ли данный скрипт в др. экземплярах данного объекта:
        $count = $this->count($field.'=:val', array(':val'=>$idPhpScript));
        if ($count == 1) {
          $phpScript->delete();
        }
      }
    }

    //$cur->updateObjectInstanceInfo(3);

    return parent::beforeDelete();
  }
  protected function afterSave() {
    $this->_idInstance = -1; // сбрасываем внутренний ид, чтоб не закэшировалось
    parent::afterSave();
  }
  protected function beforeSave() {
    if (!$this->isNewRecord) {
      $this->_pkBeforeSave = $this->getOldPrimaryKey();
    }
    return parent::beforeSave();
  }

  public function getPkBeforeSave() {
    return $this->_pkBeforeSave;
  }

  public function getBackendEventHandler() {
    return array();
  }

}