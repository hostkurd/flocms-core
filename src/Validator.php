<?php
namespace FloCMS\Core;

use Exception;
interface RuleInterface
{
    public function passes ($value):bool;
    public function message ($attribute):string;
}
/**
 * Rules for Validation
 */
class RequiredRule implements RuleInterface
{
    public function passes ($value):bool
    {
        return isset($value) && $value !== '';
    }

    public function message ($attribute):string
    {
        return "$attribute is Required.";
    }
}

class StringRule implements RuleInterface
{
    public function passes ($value):bool
    {
        return is_string($value);
    }

    public function message ($attribute):string
    {
        return "$attribute must be a string.";
    }
}

class IntegerRule implements RuleInterface
{
    /**
     * Accepts integers and integer strings from forms/JSON ("5", "-12").
     */
    public function passes ($value):bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public function message ($attribute):string
    {
        return "$attribute must be a Integer.";
    }
}

class NumericRule implements RuleInterface
{
    /**
     * Accepts ints, finite floats and numeric strings ("12.5", "1e3").
     */
    public function passes ($value):bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && is_numeric($value);
    }

    public function message ($attribute):string
    {
        return "$attribute must be a number.";
    }
}

/**
 * Shared size logic for min/max.
 *
 * When the field also has the 'integer' or 'numeric' rule, a numeric value is
 * compared as a number (min:1000 on a price). Otherwise strings are compared
 * by length in characters (min:4 on a PIN like "0123") and arrays by count.
 */
abstract class SizeRule implements RuleInterface
{
    protected $limit;
    protected bool $numeric;

    public function __construct($limit, bool $numeric = false){
        $this->limit = $limit;
        $this->numeric = $numeric;
    }

    protected function size($value): int|float|null
    {
        if ($this->numeric && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return $value + 0;
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            $string = (string) $value;
            return $string === '' ? null : mb_strlen($string, 'UTF-8');
        }

        return null;
    }

    protected function limitValue(): int|float
    {
        return is_numeric($this->limit) ? $this->limit + 0 : 0;
    }
}

class MinRule extends SizeRule
{
    public function passes ($value):bool
    {
        $size = $this->size($value);

        return $size !== null && $size >= $this->limitValue();
    }

    public function message ($attribute):string
    {
        return $this->numeric
            ? "$attribute must be at least $this->limit."
            : "$attribute must be at least $this->limit characters.";
    }
}

class MaxRule extends SizeRule
{
    public function passes ($value):bool
    {
        $size = $this->size($value);

        return $size !== null && $size <= $this->limitValue();
    }

    public function message ($attribute):string
    {
        return $this->numeric
            ? "$attribute must not be greater than $this->limit."
            : "$attribute must not exceed $this->limit characters.";
    }
}

class InRule implements RuleInterface
{
    protected $rawData;
    protected $valueArray;

    public function __construct($valueArray){

        $this->rawData = $valueArray;
        $this->valueArray = explode(',',$valueArray);
    }

    public function passes ($value):bool
    {
        return in_array($value, $this->valueArray);
    }

    public function message ($attribute):string
    {
        return "$attribute should be ( $this->rawData ).";
    }
}
/**
 * Validation Exception Class
 */
class ValidationException extends Exception
{
    protected array $errors;
    public function __construct(array $errors, $message = "Validation Failed", $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors():array
    {
        return $this->errors;
    }
}

/**
 * Validator Class
 */
class Validator
{
    protected static $ruleMap = [
        'required' => RequiredRule::class,
        'string' => StringRule::class,
        'integer' => IntegerRule::class,
        'numeric' => NumericRule::class,
        'min' => MinRule::class,
        'max' => MaxRule::class,
        'in' => InRule::class
    ];

    public static function validate(array $data = [], array $rules = [])
    {
        $errors = [];
        foreach($rules as $field => $ruleSet){
            $rulesArray = explode('|', $ruleSet);
            $ruleNames = array_map(static fn ($rule) => explode(':', $rule, 2)[0], $rulesArray);
            // min/max compare numbers only when the field is declared numeric
            $isNumericField = (bool) array_intersect($ruleNames, ['integer', 'numeric']);

            foreach($rulesArray as $rule){
                $parts = explode(':',$rule,2);
                $ruleName = $parts[0];
                $parameter = $parts[1] ?? null;

                if (isset(self::$ruleMap[$ruleName])){
                    $class = self::$ruleMap[$ruleName];
                    $ruleInstance = match (true) {
                        is_subclass_of($class, SizeRule::class) => new $class($parameter, $isNumericField),
                        $parameter !== null => new $class($parameter),
                        default => new $class,
                    };

                    $value = $data[$field] ?? null;

                    if(!$ruleInstance->passes($value)){
                        $errors[$field][] = $ruleInstance->message($field);
                    }
                }
            }
        }

        if(!empty($errors)){
            throw new ValidationException($errors);
        }
    }

}