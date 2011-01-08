<?php
App::import('Model', 'SysParameter');

class Toolkit {

  function &getInstance() {
    static $instance = array();
    if (!$instance) {
      $instance[0] =& ClassRegistry::getObject('auth_component');
    }
    return $instance[0];
  }

  static function getUser() {
    $_this =& Toolkit::getInstance();
    return $_this->user();
  }

  static function getUserId() {
    $_this =& Toolkit::getInstance();
    $user = $_this->user();
    return Set::extract($user, 'User.id');
  }

  /*static function getUserGroupId() {
    $_this =& Toolkit::getInstance();
    $user = $_this->user();
    return Set::extract($user, 'User.user_group_id');
  }*/

  static function formatDate($timestamp) {
    $sys_parameter = new SysParameter;
    $data = $sys_parameter->findParameter('display.date_format');
    $dateFormat = $data['SysParameter']['parameter_value'];

    if (is_string($timestamp)) {
      return date($dateFormat,strtotime($timestamp));
    } else if(is_numeric($timestamp)){
      return date($dateFormat, $timestamp);
    } else {
      return "";
    }
  }
}