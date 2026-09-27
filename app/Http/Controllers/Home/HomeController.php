<?php

namespace App\Http\Controllers\Home;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\BaseController;
use App\Models\Pay;
use App\Support\GeetestCaptcha;
use Illuminate\Http\Request;

class HomeController extends BaseController
{
    private $goodsService;
    private $payService;

    public function __construct()
    {
        $this->goodsService = app('Service\\GoodsService');
        $this->payService = app('Service\\PayService');
    }

    public function index(Request $request)
    {
        return $this->render('static_pages/home', ['data' => $this->goodsService->withGroup()], __('dujiaoka.page-title.home'));
    }

    public function buy(int $id)
    {
        try {
            $goods = $this->goodsService->detail($id);
            $this->goodsService->validatorGoodsStatus($goods);
            if (count($goods->coupon)) $goods->open_coupon = 1;
            $goods = $this->goodsService->format($goods);
            $client = app('Jenssegers\\Agent')->isMobile() ? Pay::PAY_CLIENT_MOBILE : Pay::PAY_CLIENT_PC;
            $goods->payways = $this->payService->pays($client);
            return $this->render('static_pages/buy', $goods, $goods->gd_name);
        } catch (RuleValidationException $e) {
            return $this->err($e->getMessage());
        }
    }

    public function geetest(Request $request)
    {
        return response()->json(app(GeetestCaptcha::class)->register($request));
    }
}
