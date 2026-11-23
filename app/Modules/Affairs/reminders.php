<?php
/**
 * Default reminder rules (design §13.2–13.3). Synced by `php omps seed` only when a rule does not
 * exist yet; changes made in 系统管理 → 提醒规则 are kept.
 * Trigger: mode, amount, unit, time (HH:MM), weekday (1–7), month_day (1–28), repeat (wday|day|once).
 */
return array(
    // received item not opened: 4 work hours after it was sent, again the next workday 09:00
    array('code' => 'issue_unread', 'level' => 'info', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 10, 'triggers' => array(
        array('mode' => 'evt', 'amount' => 4, 'unit' => 'wh'),
        array('mode' => 'evt', 'amount' => 1, 'unit' => 'wd', 'time' => '09:00'),
    )),
    // order with a deadline: 1 day before 09:00, on the day 09:00, 2 hours before
    array('code' => 'issue_due', 'level' => 'warn', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 20, 'triggers' => array(
        array('mode' => 'before', 'amount' => 1, 'unit' => 'd', 'time' => '09:00'),
        array('mode' => 'onday', 'time' => '09:00'),
        array('mode' => 'before', 'amount' => 2, 'unit' => 'h'),
    )),
    // overdue: every workday 09:00, at most 10 times; the sender is told who is overdue the first time
    array('code' => 'issue_overdue', 'level' => 'urgent', 'max_repeat' => 10, 'escalate' => 1, 'sort' => 30, 'triggers' => array(
        array('mode' => 'after', 'repeat' => 'wday', 'time' => '09:00'),
    )),
    // today's day plan without a result: every workday 17:00
    array('code' => 'perf_daily', 'level' => 'info', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 40, 'triggers' => array(
        array('mode' => 'wday', 'time' => '17:00'),
    )),
    // week / month / quarter / annual plan ending without a result: 1 workday before the end, 09:00
    array('code' => 'perf_period', 'level' => 'info', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 50, 'triggers' => array(
        array('mode' => 'before', 'amount' => 1, 'unit' => 'wd', 'time' => '09:00'),
    )),
    // results or profile changes waiting for review: 24 hours after submission
    array('code' => 'review_pending', 'level' => 'info', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 60, 'triggers' => array(
        array('mode' => 'evt', 'amount' => 24, 'unit' => 'h'),
    )),
    // device licence expiring (phase 6): 30 and 7 days before 09:00, on the day 09:00 → device admins
    array('code' => 'license_expire', 'level' => 'warn', 'max_repeat' => 0, 'escalate' => 0, 'sort' => 70, 'triggers' => array(
        array('mode' => 'before', 'amount' => 30, 'unit' => 'd', 'time' => '09:00'),
        array('mode' => 'before', 'amount' => 7, 'unit' => 'd', 'time' => '09:00'),
        array('mode' => 'onday', 'time' => '09:00'),
    )),
);
