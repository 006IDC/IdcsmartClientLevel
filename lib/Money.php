<?php

namespace addon\idcsmart_client_level\lib;

/**
 * 金额运算只使用十进制定点字符串，禁止 float 参与财务计算。
 */
class Money
{
    public static function normalize($value, $scale = 2)
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return bcadd('0', '0', $scale);
        }
        return bcadd($value, '0', $scale);
    }

    public static function add($left, $right)
    {
        return bcadd(self::normalize($left), self::normalize($right), 2);
    }

    public static function subtract($left, $right)
    {
        return bcsub(self::normalize($left), self::normalize($right), 2);
    }

    public static function multiply($left, $right)
    {
        return bcmul(self::normalize($left), self::normalize($right), 2);
    }

    public static function percent($amount, $percent)
    {
        $amount = self::maxZero($amount);
        $percent = self::maxZero($percent);
        return bcdiv(bcmul($amount, $percent, 6), '100', 2);
    }

    public static function min($left, $right)
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        return self::compare($left, $right) <= 0 ? $left : $right;
    }

    public static function max($left, $right)
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        return self::compare($left, $right) >= 0 ? $left : $right;
    }

    public static function maxZero($value)
    {
        $value = self::normalize($value);
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : $value;
    }

    public static function compare($left, $right)
    {
        return bccomp(self::normalize($left), self::normalize($right), 2);
    }

    public static function discount($amount, $percent)
    {
        $amount = self::maxZero($amount);
        $percent = self::normalize($percent);
        if (bccomp($percent, '0.00', 2) < 0) {
            $percent = '0.00';
        }
        if (bccomp($percent, '100.00', 2) > 0) {
            $percent = '100.00';
        }
        return bcdiv(bcmul($amount, $percent, 4), '100', 2);
    }
}
