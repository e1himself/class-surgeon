<?php

namespace Axis\ClassSurgeon;

/**
 * @author Ivan Voskoboynyk
 */
class Spider
{

  protected $tokens;
  protected $instructions = array();
  protected $state;

  protected $currentConstrain;
  protected $currentConstrainLogic;
  protected $currentInstruction;

  protected $result;
  protected $results = array();

  protected $position = 0;
  protected $step = 0;
  protected $positions = array();

  protected $nestingLevel = 0;
  protected $levels = array();

  protected $seekingActions = array(
    'GoForward',
    'GoBackward',
    'GoForwardForToken',
    'GoBackwardForToken'
  );
  protected $actionSteps = array(
    'GoForward' => 1,
    'GoBackward' => -1,
    'GoForwardForToken' => 1,
    'GoBackwardForToken' => -1,
  );

  public function __construct($data)
  {
    if (is_array($data))
    {
      $this->tokens = $data;
    }
    else
    {
      $this->tokens = token_get_all($data);
      $this->recalculateNestingLevel();
    }
  }

  public function getTokens()
  {
    $this->execute();
    return $this->tokens;
  }

  public function getPosition($alias = null)
  {
    $this->execute();
    if ($alias)
    {
      return $this->positions[$alias];
    }
    else
    {
      return $this->position;
    }
  }

  public function getResult($alias = null)
  {
    $this->execute();
    if ($alias)
    {
      return $this->results[$alias];
    }
    else
    {
      return $this->result;
    }
  }

  public function getToken($position = null)
  {
    $this->execute();
    if ($position === null)
    {
      return $this->tokens[$this->position];
    }
    else
    {
      if (is_string($position))
      {
        $position = $this->doRestorePosition($position);
      }
      return $this->tokens[$position];
    }
  }

  public function getText()
  {
    $this->execute();
    return $this->concatTokens($this->tokens);
  }

  /**
   * Creates a new instance of a spider
   * @param mixed $data Can be one of the following:
   * - An array of tokens
   * - A string containing code to be parsed
   * - An instance of AxisTokenSpider to be cloned
   * @return $this
   */
  public static function spawn($data)
  {
    if ($data instanceof self)
    {
      return clone $data;
    }
    else
    {
      return new self($data);
    }
  }

  /**
   * Creates a new instance of a spider using current state
   * @return $this
   */
  public function spawnClone()
  {
    $this->execute();
    return clone $this;
  }

  /**
   *
   * @param int $position
   * @return $this
   */
  public function jumpTo($position)
  {
    $this->newInstruction('JumpTo', $position);
    return $this;
  }

  /**
   *
   * @param mixed $what token
   * @return $this
   */
  public function goFor($what)
  {
    $this->newInstruction('GoForwardForToken', $what);
    return $this;
  }

  /**
   *
   * @param mixed $what
   * @return $this
   */
  public function goBackFor($what)
  {
    $this->newInstruction('GoBackwardForToken', $what);
    return $this;
  }

  /**
   *
   * @return $this
   */
  public function goForward()
  {
    $this->newInstruction('GoForward');
    return $this;
  }

  /**
   *
   * @return $this
   */
  public function goBackward()
  {
    $this->newInstruction('GoBackward');
    return $this;
  }

  /**
   *
   * @param string $alias
   * @return $this
   */
  public function rememberPosition($alias = '')
  {
    $this->newInstruction('RememberPosition', $alias);
    return $this;
  }

  /**
   *
   * @param string $alias
   * @return $this
   */
  public function restorePosition($alias = 'default')
  {
    $this->newInstruction('RestorePosition', $alias);
    return $this;
  }

  /**
   *
   * @param string $alias
   * @return $this
   */
  public function rememberResult($alias = 'default')
  {
    $this->newInstruction('RememberResult', $alias);
    return $this;
  }

  /**
   *
   * @param string $alias
   * @return $this
   */
  public function restoreResult($alias = 'default')
  {
    $this->newInstruction('RestoreResult', $alias);
    return $this;
  }

  /**
   *
   * @return $this
   */
  public function andThan()
  {
    $this->flushInstructions();
    return $this;
  }

  /**
   *
   * @param string $what
   * @return $this
   */
  public function keeping($what)
  {
    $this->newConstrainGroup('like', $what);
    return $this;
  }

  /**
   *
   * @param string $what
   * @return $this
   */
  public function stopBefore($what)
  {
    $this->newConstrainGroup('hate', $what);
    return $this;
  }

  /**
   *
   * @param array $values
   * @return $this
   */
  public function in($values)
  {
    $this->newConstrain('in', $values);
    return $this;
  }

  /**
   *
   * @param int $value
   * @return $this
   */
  public function lessThan($value)
  {
    $this->newConstrain('<', $value);
    return $this;
  }

  /**
   *
   * @param int $value
   * @return $this
   */
  public function lessOrEqualThan($value)
  {
    $this->newConstrain('<=', $value);
    return $this;
  }

  /**
   *
   * @param int $value
   * @return $this
   */
  public function greaterOrEqualThan($value)
  {
    $this->newConstrain('>=', $value);
    return $this;
  }

  /**
   *
   * @param int $value
   * @return $this
   */
  public function greaterThan($value)
  {
    $this->newConstrain('>', $value);
    return $this;
  }

  /**
   *
   * @param string $value
   * @return $this
   */
  public function equal($value)
  {
    $this->newConstrain('=', $value);
    return $this;
  }

  /**
   *
   * @param int $min
   * @param int $max
   * @return $this
   */
  public function between($min, $max)
  {
    $this->newConstrain('between', array($min, $max));
    return $this;
  }

  /**
   * @return $this
   */
  public function filter()
  {
    $this->newInstruction('Filter');
    return $this;
  }

  /**
   * @param null $from
   * @param null $to
   * @return $this
   */
  public function crop($from = null, $to = null)
  {
    $this->newInstruction('Crop', array($from, $to));
    return $this;
  }

  /**
   * @param null $from
   * @param null $to
   * @return $this
   */
  public function cut($from = null, $to = null)
  {
    $this->newInstruction('Cut', array($from, $to));
    return $this;
  }

  /**
   * @param $search
   * @param $replace
   * @return $this
   */
  public function replace($search, $replace)
  {
    $this->newInstruction('Replace', array($search, $replace));
    return $this;
  }

  /**
   * @param $from
   * @param $to
   * @param $by
   * @return $this
   */
  public function splice($from, $to, $by)
  {
    $this->newInstruction('Splice', array($from, $to, $by));
    return $this;
  }

  public function insert($what, $position = null)
  {
    $this->newInstruction('Insert', array($what, $position));
    return $this;
  }


  protected function flushConstrains()
  {
    if ($this->currentConstrain)
    {
      $this->currentInstruction['keep'][] = $this->currentConstrain;
      $this->currentConstrain = null;
    }
  }

  protected function newConstrainGroup($logic, $field)
  {
    $this->flushConstrains();
    $this->currentConstrainLogic = $logic;
    $this->currentConstrain = array(
      'field' => $field,
      'like' => array(),
      'hate' => array()
    );
  }

  protected function newConstrain($type, $arg)
  {
    $this->currentConstrain[$this->currentConstrainLogic][$type] = $arg;
  }

  protected function flushInstructions()
  {
    if ($this->currentInstruction)
    {
      $this->flushConstrains();
      $this->instructions[] = $this->currentInstruction;
      $this->currentInstruction = null;
    }
  }

  protected function newInstruction($action, $args = null, $keep = null)
  {
    $this->flushInstructions();
    $this->currentInstruction = array(
      'action' => $action,
      'args' => $args,
      'keep' => $keep ? $keep : array()
    );
  }

  protected function flush()
  {
    $this->flushInstructions();
  }

  protected function updateNestingLevel()
  {
    $this->level = $this->levels[$this->position];
  }

  protected function recalculateNestingLevel()
  {
    $this->positions = array();

    $level = 0;
    $prevToken = '';
    for ($i = 0; $i < count($this->tokens); $i++)
    {
      $token = $this->getTokenText($this->tokens[$i]);
      if ($token == '}')
      {
        $level--;
      }
      if ($prevToken == '{')
      {
        $level++;
      }
      $prevToken = $token;
      $this->levels[$i] = $level;
    }
  }

  /**
   *
   * @return $this
   */
  public function execute()
  {
    // echo "Roger that!"; // :)
    $this->flush();

    while ($instruction = $this->getNextInstruction())
    {
      $action = $instruction['action'];
      $args = $instruction['args'];
      $constrains = $instruction['keep'];

      $this->doInstruction($action, $args, $constrains);
    }

    return $this;
  }

  protected function getNextInstruction()
  {
    return array_shift($this->instructions);
  }

  protected function doInstruction($action, $args, $constrains)
  {
    $result = null;
    $this->doPrepare($action);
    if (in_array($action, $this->seekingActions))
    {
      while ($this->constrainsOk($constrains))
      {
        $this->updateNestingLevel();
        $result = $this->{'do' . $action}($args, $constrains);
        if ($result)
        {
          break;
        }
      }
      $this->result = $result;
    }
    else
    {
      $this->{'do' . $action}($args, $constrains);
    }
    return $this->result;
  }

  protected function doPrepare($action)
  {
    if (isset($this->actionSteps[$action]))
    {
      $this->step = $this->actionSteps[$action];
    }
    else
    {
      $this->step = 0;
    }
  }

  protected function doFilter($args, $constrains)
  {
    $newTokens = array();

    $this->step = 0;
    for ($i = 0; $i < count($this->tokens); $i++)
    {
      $token = $this->tokens[$i];
      $this->position = $i;
      if ($this->constrainsOk($constrains))
      {
        $newTokens[] = $token;
      }
    }

    $this->position = 0;
    $this->tokens = $newTokens;

    $this->recalculateNestingLevel();
  }

  protected function doCrop($args)
  {
    $from = $args[0];
    $to = $args[1];

    if (!is_int($to))
    {
      if (!$to)
      {
        $this->doRestorePosition();
        $to = $this->position;
      }
      else
      {
        $to = $this->positions[$to];
      }
    }

    if (!is_int($from))
    {
      if (!$from)
      {
        $this->doRestorePosition();
        $from = $this->position;
      }
      else
      {
        $from = $this->positions[$from];
      }
    }

    $newTokens = array();

    for ($i = $from; $i <= $to; $i++)
    {
      $token = $this->tokens[$i];
      $newTokens[] = $token;
    }

    $this->position = 0;
    $this->tokens = $newTokens;
    $this->recalculateNestingLevel();
  }

  protected function doCut($args)
  {
    $from = $args[0];
    $to = $args[1];

    if (!is_int($to))
    {
      if (!$to)
      {
        $this->doRestorePosition();
        $to = $this->position;
      }
      else
      {
        $to = $this->positions[$to];
      }
    }

    if (!is_int($from))
    {
      if (!$from)
      {
        $this->doRestorePosition();
        $from = $this->position;
      }
      else
      {
        $from = $this->positions[$to];
      }
    }

    $this->result = array_splice($this->tokens, $from, $to - $from + 1);
    $this->tokens = token_get_all($this->concatTokens($this->tokens));
    $this->recalculateNestingLevel();
  }

  protected function doReplace($args)
  {
    $this->modifyTokens($this->tokens, $args[0], $args[1]);
  }

  protected function doSplice($args)
  {
    $from = $args[0];
    $to = $args[1];
    $by = $args[2];

    array_splice($this->tokens, $from, $to - $from + 1, $by);
    $this->tokens = token_get_all($this->concatTokens($this->tokens));
    $this->recalculateNestingLevel();
  }

  protected function doInsert($args)
  {
    $what = $args[0];
    $pos = $args[1];

    if ($pos === null)
    {
      $pos = $this->getPosition();
    }
    else
    {
      if (is_string($pos))
      {
        $pos = $this->getPosition($pos);
      }
    }

    if (!is_array($what))
    {
      $what = array($what);
    }

    array_splice($this->tokens, $pos, 0, $what);
    $this->tokens = token_get_all($this->concatTokens($this->tokens));
    $this->recalculateNestingLevel();
  }

  protected function doJumpTo($position)
  {
    if (is_int($position))
    {
      $this->position = $position;
    }
    else
    {
      $this->position = $this->positions[$position];
    }
  }

  protected function doRememberPosition($alias = null)
  {
    if ($alias)
    {
      $this->positions[$alias] = $this->position;
    }
    else
    {
      $this->positions[] = $this->position;
    }
  }

  protected function doRestorePosition($position = null)
  {
    if ($position)
    {
      $this->position = $this->positions[$position];
    }
    else
    {
      $this->position = array_pop($this->positions);
    }
    return $this->position;
  }

  protected function doRememberResult($alias)
  {
    $this->results[$alias] = $this->result;
  }

  protected function doRestoreResult($alias)
  {
    $this->result = $this->results[$alias];
  }

  protected function doGoForward()
  {
    $this->position++;
  }

  protected function doGoBackward()
  {
    $this->position--;
  }

  protected function doGoForwardForToken($value)
  {
    $this->position++;
    $token = $this->tokens[$this->position];
    if ($this->tokenMatches($token, $value))
    {
      return $token;
    }
    return null;
  }

  protected function doGoBackwardForToken($value)
  {
    $this->position--;
    $token = $this->tokens[$this->position];
    if ($this->tokenMatches($token, $value))
    {
      return $token;
    }
    return null;
  }

  protected function constrainsOk($constrains)
  {
    $position = $this->position;
    $step = $this->step;
    if ($position + $step >= count($this->tokens))
    {
      return false;
    }

    if ($position + $step <= 0)
    {
      return false;
    }

    $nextToken = $this->tokens[$position + $step];
    list($tokenId, $tokenText) = $this->getTokenData($nextToken);

    $nextLevel = $this->levels[$position + $step];

    foreach ($constrains as $constrain)
    {
      $field = $constrain['field'];
      switch ($field)
      {
        case 'level':
          $check = $nextLevel;
          break;
        case 'tokens':
        case 'token':
          $check = $tokenId;
          break;
        default:
          // var_dump($field);
          continue;
      }

      foreach (array('like', 'hate') as $logic)
      {
        $rules = $constrain[$logic];
        if (!$this->checkConstrains($check, $logic == 'like', $rules))
        {
          return false;
        }
      }
    }

    return true;
  }

  protected function checkConstrains($check, $good, $rules)
  {
    foreach ($rules as $logic => $value)
    {
      $passed = true;
      switch ($logic)
      {
        case '=':
          $passed = ($check == $value);
          break;
        case '<':
          $passed = ($check < $value);
          break;
        case '>':
          $passed = ($check > $value);
          break;
        case '>=':
          $passed = ($check >= $value);
          break;
        case '<=':
          $passed = ($check >= $value);
          break;
        case 'in':
          $passed = (in_array($check, $value));
          break;
        case 'between':
          list($min, $max) = $value;
          $passed = ($min <= $check) && ($check <= $max);
          break;
      }
      $passed ^= !$good;

      if (!$passed)
      {
        return false;
      }
    }
    return true;
  }

  protected function copyTokens($tokens, $startPos, $endPos)
  {
    return array_slice($tokens, $startPos, $endPos - $startPos + 1);
  }

  protected function replaceTokens($tokens, $startPos, $endPos, $newTokens = null)
  {
    array_splice($tokens, $startPos, $endPos - $startPos + 1, $newTokens);
    $this->tokens = token_get_all($this->concatTokens($this->tokens));
    $this->recalculateNestingLevel();
    return $tokens;
  }

  public function getTokenData($token)
  {
    if (is_array($token))
    {
      return $token;
    }
    else
    {
      return array($token, $token);
    }
  }

  public function getTokenText($token)
  {
    if (is_array($token))
    {
      return $token[1];
    }
    else
    {
      return $token;
    }
  }

  protected function modifyTokens($tokens, $needles, $replace)
  {

    $newTokens = array();
    $replacements = 0;

    foreach ($tokens as $token)
    {
      if ($this->tokenMatches($token, $needles))
      {
        $newTokens[] = $replace;
        $replacements++;
      }
      else
      {
        $newTokens[] = $token;
      }
    }

    $code = $this->concatTokens($newTokens);
    $this->tokens = token_get_all($code);
    $this->recalculateNestingLevel();

    return $replacements;
  }

  public function concatTokens($tokens = null)
  {
    $code = '';

    if (!$tokens)
    {
      $this->execute();
      $tokens = $this->tokens;
    }

    foreach ($tokens as $token)
    {
      $code .= $this->getTokenText($token);
    }
    return $code;
  }

  public function getTokensText($tokens = null)
  {
    $text = array();
    if (!$tokens)
    {
      $this->execute();
      $tokens = $this->tokens;
    }
    foreach ($tokens as $token)
    {
      $text[] = $this->getTokenText($token);
    }
    return $text;
  }

  public function tokenMatches($token, $match)
  {
    if (is_array($match))
    {
      return is_array($token) && ($token[0] == $match[0]) && ($token[1] == $match[1]);
    }
    list($id, $text) = $this->getTokenData($token);
    return ($id == $match || $text == $match);
  }
}
