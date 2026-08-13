<?php

require_once dirname(__DIR__) . '/lib/Money.php';

use addon\idcsmart_client_level\lib\Money;

function expectSame($expected, $actual, $label)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

expectSame('123.45', Money::normalize('123.456'), '金额固定两位小数');
expectSame('12.34', Money::discount('123.45', '10'), '减免金额向下截取两位');
expectSame('100.00', Money::discount('100.00', '120'), '减免比例上限为100%');
expectSame('0.00', Money::maxZero('-0.01'), '累计消费不小于零');
expectSame('30.30', Money::add('10.10', '20.20'), '十进制定点加法');
expectSame('79.90', Money::subtract('100.00', '20.10'), '十进制定点减法');
expectSame('20.20', Money::multiply('10.10', '2'), '十进制定点乘法');
expectSame('12.34', Money::percent('123.45', '10'), '比例金额向下截取两位');
expectSame('9.99', Money::min('9.99', '10.00'), '取较小金额');
expectSame('-1', (string) Money::compare('9.99', '10.00'), '金额比较');

echo "money_test: OK\n";
