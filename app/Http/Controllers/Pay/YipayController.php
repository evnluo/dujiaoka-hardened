<?php
namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\BaseModel;
use Illuminate\Http\Request;

class YipayController extends PayController
{

    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            //组装支付参数
            $parameter = [
                'pid' =>  $this->payGateway->merchant_id,
                'type' => $payway,
                'out_trade_no' => $this->order->order_sn,
                'return_url' => route('yipay-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
                'name'   => $this->order->order_sn,
                'money'  => (float)$this->order->actual_price,
                'sign' => $this->payGateway->merchant_pem,
                'sign_type' =>'MD5'
            ];
            ksort($parameter); //重新排序$data数组
            reset($parameter); //内部指针指向数组中的第一个元素
            $sign = '';
            foreach ($parameter as $key => $val) {
                if ($key == "sign" || $key == "sign_type" || $val == "") continue;
                if ($key != 'sign') {
                    if ($sign != '') {
                        $sign .= "&";
                    }
                    $sign .= "$key=$val"; //拼接为url参数形式
                }
            }

            $sign = md5($sign . $this->payGateway->merchant_pem);//密码追加进入开始MD5签名
            $parameter['sign'] = $sign;
            //待请求参数数组
            $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='" . $this->payGateway->merchant_key . "' method='get'>";

            foreach($parameter as $key => $val) {
                $sHtml.= "<input type='hidden' name='".$key."' value='".$val."'/>";
            }

            //submit按钮控件请不要含有name属性
            $sHtml = $sHtml."<input type='submit' value=''></form>";
            $sHtml = $sHtml."<script>document.forms['alipaysubmit'].submit();</script>";
            return $sHtml;
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }

    public function notifyUrl(Request $request)
    {
        $data = $request->all();
        if (!$this->hasValidCallbackShape($data)) {
            return 'fail';
        }
        $order = $this->orderService->detailOrderSN($data['out_trade_no']);
        if (!$order) {
            return 'fail';
        }
        if (!hash_equals((string) $order->order_sn, $data['name'])) {
            return 'fail';
        }
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'fail';
        }
        if (
            $payGateway->pay_handleroute !== '/pay/yipay'
            || (int) $payGateway->is_open !== BaseModel::STATUS_OPEN
        ) {
            return 'fail';
        }
        if (!isset($data['pid']) || !is_string($data['pid']) || !hash_equals((string) $payGateway->merchant_id, $data['pid'])) {
            return 'fail';
        }
        if (!isset($data['type']) || !is_string($data['type']) || !hash_equals((string) $payGateway->pay_check, $data['type'])) {
            return 'fail';
        }
        if (!isset($data['trade_status']) || $data['trade_status'] !== 'TRADE_SUCCESS') {
            return 'fail';
        }
        ksort($data); //重新排序$data数组
        reset($data); //内部指针指向数组中的第一个元素
        $sign = '';
        foreach ($data as $key => $val) {
            if ($key == "sign" || $key == "sign_type" || $val == "") continue;
            if ($key != 'sign') {
                if ($sign != '') {
                    $sign .= "&";
                }
                $sign .= "$key=$val"; //拼接为url参数形式
            }
        }
        $expectedSign = md5($sign . $payGateway->merchant_pem);
        if (
            !isset($data['trade_no'], $data['sign'])
            || !is_string($data['trade_no'])
            || $data['trade_no'] === ''
            || !is_string($data['sign'])
            || !preg_match('/\A[0-9a-fA-F]{32}\z/D', $data['sign'])
            || !hash_equals($expectedSign, strtolower($data['sign']))
        ) { //不合法的数据
            return 'fail';  //返回失败 继续补单
        } else {
            //合法的数据
            //业务处理
            try {
                $this->orderProcessService->completedOrder($data['out_trade_no'], (float) $data['money'], $data['trade_no']);
            } catch (RuleValidationException $exception) {
                return 'fail';
            }
            return 'success';
        }
    }

    private function hasValidCallbackShape(array $data): bool
    {
        $requiredFields = [
            'pid',
            'trade_no',
            'out_trade_no',
            'type',
            'name',
            'money',
            'trade_status',
            'sign',
            'sign_type',
        ];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data) || !is_string($data[$field]) || $data[$field] === '') {
                return false;
            }
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return false;
            }
        }
        if (
            strlen($data['out_trade_no']) > 150
            || strlen($data['name']) > 150
            || strlen($data['trade_no']) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $data['out_trade_no'] . $data['name'] . $data['trade_no'])
            || !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/D', $data['money'])
            || strcasecmp($data['sign_type'], 'MD5') !== 0
        ) {
            return false;
        }
        return true;
    }

    public function returnUrl(Request $request)
    {
        $oid = $request->get('order_id');
        // 有些易支付太垃了，异步通知还没到就跳转了，导致订单显示待支付，其实已经支付了，所以这里休眠2秒
        sleep(2);
        return redirect(url('detail-order-sn', ['orderSN' => $oid]));
    }

}
