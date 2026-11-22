<?php

return [
    'linked' => ':name，您的账号已绑定。今后通知会发到这个聊天。发送 /stop 可停止接收。',
    'link_invalid' => '这个链接无效或已过期。请在网站上重新点击“绑定”：:site',
    'stopped' => '已停止发送通知。如需重新开启，请在网站上点击“绑定”：:site',
    'help_unlinked' => '这个机器人发送“出勤”平台的通知。要接入，请登录网站，在“Telegram”部分点击“绑定”：:site',
    'help_linked' => '这个机器人只发送通知，不回答问题。完整数据在网站上：:site。停止通知：/stop',
    'round_absent' => ':class：今天有 :count 名学生缺勤。填写原因：:url',
    'left' => ':class：1 名学生从第 :lesson 节课起早退。标记是否批准：:url',
    'pending' => '有 :count 条记录等待您处理（缺勤原因或早退批准）。请在网站上标记：:url',
    'shift_morning' => '上午班',
    'shift_afternoon' => '下午班',
    'shift_summary' => ":shift，:date。\n已巡查班级：:surveyed / :total。\n全天缺勤：:absent，迟到：:late，早退：:left。:missing\n详情：:url",
    'shift_missing' => "\n未巡查：:classes。",
    'and_more' => ':classes 等另外 :count 个',
    'conflict' => ':class：两台平板的标记不一致，数字尚未最终确定。去处理：:url',
    'report_weekly' => "周报已定稿（:from – :to）。\n出勤率：:pct。已巡查的班级日：:surveyed / :total。\n无故：:unexcused，原因未确认：:pending。\n附件不含姓名；完整报告在网站上：:url",
    'report_monthly' => "月报已定稿（:from – :to）。\n出勤率：:pct。已巡查的班级日：:surveyed / :total。\n无故：:unexcused，原因未确认：:pending。\n附件不含姓名；完整报告在网站上：:url",
    'no_data' => '无数据',
];
