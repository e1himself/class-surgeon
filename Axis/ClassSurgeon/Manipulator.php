<?php

namespace Axis\ClassSurgeon;

/**
 * @author Ivan Voskoboynyk
 */
class Manipulator
{

  protected $code = '', $file = false;

  protected $skipOpenTag = false;

  /**
   * Constructor.
   *
   * @param string $code The code to manipulate
   */
  public function __construct($code)
  {
    if (strpos($code, '<' . '?php') === false)
    {
      $code = '<' . '?php /**/' . $code;
      $this->skipOpenTag = true;
    }
    $this->code = $code;
  }

  /**
   * Saves the code back to the associated file.
   *
   * This only works if you have bound the instance with a file with the setFile() method.
   *
   * @throw LogicException if no file is associated with the instance
   */
  public function save()
  {
    if (!$this->file)
    {
      throw new \LogicException('Unable to save the code as no file has been provided.');
    }

    file_put_contents($this->file, $this->code);
  }

  /**
   * Gets the modified code.
   *
   * @return string The modified code
   */
  public function getCode()
  {
    if ($this->skipOpenTag)
    {
      $tokens = token_get_all($this->code);
      $tokens = array_slice($tokens, 2);
      $this->code = Spider::spawn($tokens)->getText();
    }
    return $this->code;
  }

  /**
   * Gets the associated file.
   *
   * @return string The associated file
   */
  public function getFile()
  {
    return $this->file;
  }

  /**
   * Sets the file associated with this instance.
   *
   * @param string $file A file name
   */
  public function setFile($file)
  {
    $this->file = $file;
  }

  /**
   * @return string
   * @throws \InvalidArgumentException
   */
  public function getClassName()
  {

    $spider = Spider::spawn($this->code);

    $class_token = $spider->goFor(T_CLASS)->
      andThan()->goFor(T_STRING)->
      getResult();

    if ($class_token === false)
    {
      $message = 'Class declaration not found';
      if ($this->getFile())
      {
        $message .= ' while manipulating file: ' . $this->getFile();
      }
      throw new \InvalidArgumentException($message);
    }

    return $spider->getTokenText($class_token);
  }

  /**
   * @return string|null
   */
  public function getBaseClassName()
  {

    $spider = Spider::spawn($this->code);
    $extends_token_found = $spider->
      goFor(T_CLASS)->
      andThan()->goFor(T_EXTENDS)->stopBefore('token')->equal('{')->
      getResult();

    if (!$extends_token_found)
    {
      return null;
    }

    $base_class_token = $spider->goFor(T_STRING)->getResult();
    return $spider->getTokenText($base_class_token);
  }

  /**
   * @return array
   */
  public function getInterfaces()
  {
    $spider = Spider::spawn($this->code);
    $implements_token_found = $spider->
      goFor(T_CLASS)->
      goFor(T_IMPLEMENTS)->stopBefore('token')->equal('{')->
      getResult();

    if (!$implements_token_found)
    {
      return array();
    }

    $interfaces = $spider->rememberPosition('start')->
      goFor('{')->
      rememberPosition('end')->
      crop('start', 'end')->
      filter()->keeping('token')->in(array(T_STRING))->
      getTokensText();

    return $interfaces;
  }

  /**
   * @param string $newClassName
   * @throws \InvalidArgumentException
   *
   * @return $this
   */
  public function setClassName($newClassName)
  {
    $spider = Spider::spawn($this->code);

    $class_token = $spider->goFor(T_CLASS)->goFor(T_STRING)->getResult();

    if ($class_token === false)
    {
      $message = 'Class declaration not found';
      if ($this->getFile())
      {
        $message .= ' while manipulating file: ' . $this->getFile();
      }
      throw new \InvalidArgumentException($message);
    }

    $class_name_pos = $spider->getPosition();
    $spider->splice($class_name_pos, $class_name_pos, $newClassName)->execute();

    $this->code = $spider->getText();

    return $this;
  }

  /**
   * @param string $newBaseClassName
   */
  public function setBaseClassName($newBaseClassName)
  {

    $spider = Spider::spawn($this->code);

    $extends_found = $spider->goFor(T_EXTENDS)->stopBefore('token')->equal('{')->getResult();

    if ($extends_found)
    {

      $base_class_pos = $spider->goFor(T_STRING)->getPosition();
      $spider->splice($base_class_pos, $base_class_pos, $newBaseClassName);

      $this->code = $spider->getText();

    }
    else
    {

      $pos = $spider->jumpTo(0)->goFor(T_CLASS)->
        goForward()->stopBefore('token')->in(array(T_IMPLEMENTS, '{'))->
        getPosition();

      $last_token = $spider->getToken();


      if ($spider->tokenMatches($last_token, T_STRING))
      {
        $prefix = ' ';
      }
      else
      {
        $prefix = '';
      }

      $spider->insert(array($prefix . 'extends ', $newBaseClassName, ' '), $pos + 1);

      $this->code = $spider->getText();
    }
  }

  /**
   * Adds interface to class declaration
   *
   * @param string|array $interfacesToAdd
   * @return $this
   */
  public function addInterface($interfacesToAdd)
  {
    $interfaces = $this->getInterfaces();

    $interfacesToAdd = $this->ensureArray($interfacesToAdd);
    $interfacesToAdd = array_diff($interfacesToAdd, $interfaces);
    if (count($interfacesToAdd) == 0)
    {
      return $this;
    }

    $spider = Spider::spawn($this->code);

    $pos = $spider->goFor(T_CLASS)->
      goForward()->keeping('token')->in(array(T_STRING, T_COMMENT, T_WHITESPACE, ',', T_EXTENDS, T_IMPLEMENTS))->
      getPosition();

    $last_token = $spider->getToken();

    if (!$spider->tokenMatches($last_token, T_WHITESPACE))
    {
      $pos++;
    }

    if (count($interfaces) > 0)
    {
      $prefix = ', ';
    }
    else
    {
      $prefix = ' implements ';
    }

    $spider->insert($prefix . implode(', ', $interfacesToAdd), $pos);

    $this->code = $spider->getText();

    return $this;
  }

  /**
   * Return class declaration string (class Foo extends Bar implements Interface {)
   *
   * @param bool $withEverythingBefore
   * @return string
   */
  public function getClassDeclaration($withEverythingBefore = false)
  {

    $spider = Spider::spawn($this->code);

    $class_pos = $spider->goFor(T_CLASS)->getPosition();

    if ($withEverythingBefore)
    {
      $start_pos = $spider->goFor(T_OPEN_TAG)->getPosition() + 1;
    }
    else
    {
      $start_pos = $spider->goBackward()->
        keeping('token')->in(array(T_ABSTRACT, T_FINAL, T_WHITESPACE, T_COMMENT))->
        stopBefore('token')->equal(T_OPEN_TAG)->getPosition();
    }

    $end_pos = $spider->goForward()->stopBefore('token')->equal('{')->getPosition();

    $spider->crop($start_pos, $end_pos)->execute();

    return $spider->getText();
  }

  /**
   * Return class body
   *
   * @return string
   */
  public function getClassBody()
  {

    $spider = Spider::spawn($this->code);
    $spider->filter()->keeping('level')->greaterThan(0);
    return $spider->getText();
  }

  /**
   * Return method body
   *
   * @param $methodName
   * @return null|string
   */
  public function getMethodBody($methodName)
  {
    $spider = Spider::spawn($this->code);

    $spider->goFor(T_CLASS);

    while ($token = $spider->goFor(T_FUNCTION)->getResult())
    {
      $methodToken = $spider->goFor(T_STRING)->getResult();
      $foundMethodName = $spider->getTokenText($methodToken);
      if ($foundMethodName == $methodName)
      {
        $start_pos = $spider->goFor('{')->getPosition() + 1;
        $end_pos = $spider->goForward()->keeping('level')->greaterThan(1)->getPosition();
        return $spider->crop($start_pos, $end_pos)->getText();
      }
    }
    return null;
  }

  /**
   * Wrap method body with code
   *
   * @param string $methodName
   * @param string $topCode
   * @param string $bottomCode
   *
   * @return $this
   */
  public function wrapMethod($methodName, $topCode = '', $bottomCode = '')
  {
    $spider = Spider::spawn($this->code);

    while ($token = $spider->goFor(T_FUNCTION)->getResult())
    {
      $methodToken = $spider->goFor(T_STRING)->getResult();
      $foundMethodName = $spider->getTokenText($methodToken);
      if ($foundMethodName == $methodName)
      {
        $insert_pos = $spider->goFor('{')->getPosition() + 1;
        $spider->insert($topCode, $insert_pos)->execute();
        $spider->jumpTo($insert_pos);
        $end_pos = $spider->goForward()->keeping('level')->greaterThan(1)->getPosition();
        $spider->insert($bottomCode);
        $this->code = $spider->getText();
      }
    }
    return $this;
  }

  /**
   * @param mixed $value
   * @return array
   */
  protected function ensureArray($value)
  {
    if ($value && !is_array($value))
    {
      return array($value);
    }
    return $value;
  }

  /**
   * @param array $array
   * @param mixed $value
   * @return array
   */
  protected function ensureContains($array, $value)
  {
    if (!in_array($value, $array))
    {
      $array[] = $value;
    }
    return $array;
  }

  /**
   * Creates a manipulator object from a file.
   *
   * @param string $file A file name
   *
   * @return $this A manipulator instance
   */
  static public function fromFile($file)
  {
    /** @var Manipulator $manipulator */
    $manipulator = new static(file_get_contents($file));
    $manipulator->setFile($file);

    return $manipulator;
  }
}
