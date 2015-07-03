<?php

use AmiLabs\DevKit\Request;
use AmiLabs\DevKit\Registry;
use AmiLabs\PingReports\DataAccess;

/**
 * JSON-RPC service.
 */

$appName = 'json';
require_once '../app/init.php';

$config = Registry::useStorage('CFG');
$request = Request::getInstance($config->get('request/type', 'uri'));

$services = array_keys($config->get('services'));
$service = $request->get('service', FALSE);
if (
    FALSE === $service ||
    !in_array($service, $services)
) {
    $service = reset($services);
}
$view = $request->get('view', 'uptime');
if (!in_array($view, array('uptime', 'details'))) {
    $view = 'uptime';
}

$dal = DataAccess::getLayer($config->get('dataAccess/layer'));
$dal->init($config->get('dataAccess'));

// header('Content-Type: application/json');
header('Content-Type: text/javascript');
echo sprintf(
    "%s([\n" .
    "[\"%s\",\"%s\"],\n",
    $request->get('callback', 'callback'),
    str_replace('.', '-', $service),
    $view
);

switch ($view) {
    case 'uptime':
        // Get min date
        $borderDates = $dal->getBorderDates($service);
        if (!$borderDates) {
            // No records
            break;
        }

        $time = strtotime($borderDates['min_date']);
        $maxDate = substr($borderDates['max_date'], 0, -6);
        $month = 0;
        do {
            $timeFrom = mktime(0, 0, 0, date('m', $time) + $month, 1, date('Y', $time));
            $yearMonthFrom = date('Y-m', $timeFrom) . '-01';
            $timeTo = mktime(0, 0, 0, date('m', $time) + $month + 1, 1, date('Y', $time));
            $yearMonthTo = date('Y-m', $timeTo) . '-01';

            $records = $dal->get(
                array(
                    "SUBSTR(`date`, 1, 13) `date_hour`",
                    // "SUM(1) `total`",
                    "SUM(CASE (`status`) WHEN 'F' THEN 1 ELSE 0 END) `failed`",
                ),
                array(
                    array(
                        'field' => 'service',
                        'value' => $service,
                    ),
                    array(
                        'field' => 'date',
                        'op'    => '>=',
                        'value' => $yearMonthFrom,
                    ),
                    array(
                        'field' => 'date',
                        'op'    => '<',
                        'value' => $yearMonthTo,
                    ),
                ),
                0,
                0,
                "SUBSTR(`date`, 1, 13)"
            );
            $lastFoundRecordIndex = 0;
            for ($day = 1; $day < 32; ++$day) {
                $date = sprintf("%s-%02d", date('Y-m', $timeFrom), $day);
                for ($hour = 0; $hour < 24; ++$hour) {
                    $dateHour = sprintf("%s %02d", $date, $hour);
                    if ($dateHour . ':59:59' < $borderDates['min_date']) {
                        continue;
                    }
                    $failed = 0;
                    if (
                        isset($records[$lastFoundRecordIndex]) &&
                        $dateHour == $records[$lastFoundRecordIndex]['date_hour']
                    ) {
                        $failed = $records[$lastFoundRecordIndex]['failed'];
                        ++$lastFoundRecordIndex;
                    }
                    $percent = (60 - $failed) * 100 / 60;
                    $last = $dateHour >= $maxDate;
                    echo sprintf(
                        "[%d,%d,%d,%d,%.2f]%s\n",
                        date('Y', $timeFrom),
                        date('m', $timeFrom),
                        $day,
                        $hour,
                        $percent,
                        $last ? '' : ','
                    );
                    if ($last) {
                        break 3;
                    }
                }
            }
            flush();
            ++$month;

        } while (TRUE);

        break; // case 'uptime'

    case 'details':
        $start = 0;
        $limit = 500;
        do {
            $records = $dal->get(
                array('date', 'status', 'connect_time', 'total_time'),
                array(
                    array(
                        'field' => 'service',
                        'value' => $service,
                    ),
                ),
                $start,
                $limit
            );
            $qty = sizeof($records);
            if (!$qty) {
                break;
            }

            foreach ($records as $index => $record) {
                $time = strtotime($record['date']);
                echo sprintf(
                    // "[Date.UTC(%d,%d,%d,%d,%d,%d),%.4f]%s\n",
                    "[%d,%d,%d,%d,%d,\"%s\",%.3f,%.3f]%s\n",
                    date('Y', $time),
                    date('m', $time),
                    date('d', $time),
                    date('H', $time),
                    date('i', $time),
                    // date('s', $time),
                    $record['status'],
                    $record['connect_time'],
                    $record['total_time'],
                    ($index + 1) < $qty ? ',' : ''
                );
            }
            flush();

            $start += $limit;
        } while(TRUE);

        break; // case 'details'
}

echo "]);\n";