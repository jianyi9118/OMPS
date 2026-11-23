<?php
namespace Omps\Core;

/**
 * Input validation. Rules: required, nullable, string, int, bool, in:a,b, max:n, min:n,
 * date, email, regex:/.../, array. Labels are translation keys.
 *
 *   $v = Validator::check($input, ['name' => 'required|string|max:50'], ['name' => 'field.name']);
 *   // returns cleaned values, or throws ValidationException
 */
class Validator
{
    public static function check(array $input, array $rules, array $labels = array())
    {
        $errors = array();
        $clean = array();
        foreach ($rules as $field => $ruleStr) {
            $rs = is_array($ruleStr) ? $ruleStr : explode('|', $ruleStr);
            $label = isset($labels[$field]) ? __($labels[$field]) : $field;
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || (is_array($value) && !$value);
            $nullable = in_array('nullable', $rs, true);
            if (in_array('required', $rs, true) && $empty) {
                $errors[$field] = __('validation.required', array('field' => $label));
                continue;
            }
            if ($empty) {
                if ($present || $nullable) {
                    $clean[$field] = in_array('bool', $rs, true) ? 0 : (in_array('array', $rs, true) ? array() : null);
                }
                continue;
            }
            $err = null;
            foreach ($rs as $rule) {
                $arg = '';
                if (strpos($rule, ':') !== false) {
                    list($rule, $arg) = explode(':', $rule, 2);
                }
                $err = self::apply($rule, $arg, $value, $label);
                if ($err !== null) {
                    break;
                }
            }
            if ($err !== null) {
                $errors[$field] = $err;
            } else {
                $clean[$field] = $value;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /** @param mixed $value passed by reference so casts stick */
    private static function apply($rule, $arg, &$value, $label)
    {
        switch ($rule) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    return __('validation.string', array('field' => $label));
                }
                $value = (string) $value;
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    return __('validation.string', array('field' => $label));
                }
                return null;
            case 'int':
                if (is_int($value)) {
                    return null;
                }
                if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
                    $value = (int) $value;
                    return null;
                }
                return __('validation.int', array('field' => $label));
            case 'bool':
                if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
                    $value = 1;
                    return null;
                }
                if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
                    $value = 0;
                    return null;
                }
                return __('validation.bool', array('field' => $label));
            case 'array':
                return is_array($value) ? null : __('validation.array', array('field' => $label));
            case 'in':
                $opts = explode(',', $arg);
                return in_array((string) $value, $opts, true) ? null : __('validation.in', array('field' => $label));
            case 'max':
                if (is_int($value)) {
                    return $value > (int) $arg ? __('validation.max_num', array('field' => $label, 'max' => $arg)) : null;
                }
                return mb_strlen((string) $value) > (int) $arg ? __('validation.max', array('field' => $label, 'max' => $arg)) : null;
            case 'min':
                if (is_int($value)) {
                    return $value < (int) $arg ? __('validation.min_num', array('field' => $label, 'min' => $arg)) : null;
                }
                return mb_strlen((string) $value) < (int) $arg ? __('validation.min', array('field' => $label, 'min' => $arg)) : null;
            case 'date':
                $s = (string) $value;
                $d = \DateTime::createFromFormat('Y-m-d', $s);
                return ($d !== false && $d->format('Y-m-d') === $s) ? null : __('validation.date', array('field' => $label));
            case 'email':
                return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false ? null : __('validation.email', array('field' => $label));
            case 'regex':
                return preg_match($arg, (string) $value) === 1 ? null : __('validation.format', array('field' => $label));
            case 'code':
                return preg_match('/^[A-Za-z0-9_\-]{1,50}$/', (string) $value) === 1 ? null : __('validation.code', array('field' => $label));
        }
        throw new \InvalidArgumentException('Unknown validation rule: ' . $rule);
    }
}
