<?php
// Public-looking, synthetic catalog only. No customer, payment or inventory records.
$groups = [];
foreach (['ChatGPT 镜像站', 'ChatGPT 订阅代充', 'ChatGPT 订阅成品号'] as $index => $name) {
    $goods = [];
    for ($i = 0; $i < 8; $i++) {
        $id = $index * 8 + $i + 1;
        $goods[] = [
            'id' => $id, 'group_id' => $index + 1,
            'gd_name' => ($index === 0 ? '镜像站 ' : ($index === 1 ? '代充 ' : '成品号 ')) . ($i % 2 ? 'Pro' : 'Plus') . ' | 1个月',
            'picture' => "fixture-$id.svg", 'type' => $index === 1 ? 2 : 1,
            'actual_price' => $index === 0 ? ($i % 2 ? '45.99' : '27.99') : '3585.00',
            'in_stock' => $id === 24 ? 0 : 12, 'ord' => 100 - $id,
        ];
    }
    $groups[] = ['id' => $index + 1, 'gp_name' => $name, 'goods' => $goods];
}
return $groups;
