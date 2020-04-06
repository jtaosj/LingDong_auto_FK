<?php
/**
 * 微信公众号支付
 * @author mapeijian
 */
namespace app\common\pay;

use think\Db;
use think\Request;
use app\common\Pay;
use think\Loader;
use think\Session;

class WxJsApi extends Pay{
    protected $code='';
    protected $error='';

    public function getCode()
    {
        return $this->code;
    }

    public function getError()
    {
        return $this->error;
    }

    /**
     * 支付
     * @param string $outTradeNo 外部单号
     * @param string $subject 标题
     * @param float $totalAmount 支付金额
     */
    public function order($outTradeNo,$subject,$totalAmount)
    {
        if (strpos(@$_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false) {
            $pay_type = 'JSAPI';
            $openid = Session::get('openid');
            Loader::import('wxpay.WxPayJsApiPay');
            Loader::import('wxpay.WxPayApi.php');
            Loader::import('wxpay.WxPayConfig.php');
            Loader::import('wxpay.WxPayJsApiPay');
            $tools = new \JsApiPay();
            if(empty(Session::get('openid'))){
	            $data = $tools->GetOpenid();
	            $openid = $data['openid'];
	            Session::set('openid',$openid);
            }
            $input = new \WxPayUnifiedOrder();
			$input->SetBody($subject);
			$input->SetAttach($this->createNoncestr());
			$input->SetOut_trade_no($outTradeNo);
			$input->SetTotal_fee($totalAmount * 100);
			$input->SetTime_start(date("YmdHis"));
			$input->SetTime_expire(date("YmdHis", time() + 600));
			$input->SetGoods_tag($subject);
			$input->SetNotify_url(Request::instance()->domain().'/pay/notify/WxJsApi');
			$input->SetTrade_type("JSAPI");
			$input->SetOpenid($openid);
			$this->defineWxConfig($this->account->params);
			$config = new \WxPayConfig();
			$order = \WxPayApi::unifiedOrder($config, $input);
			$jsApiParameters = $tools->GetJsApiParameters($order);
			$editAddress = $tools->GetEditAddressParameters();
			$result = $jsApiParameters;
        }else{
            $pay_type = 'MWEB';
        }
        $this->code    =0;
        $obj           =new \stdClass();
        $obj->pay_url  =$result;
        $obj->content_type = 5;
        return $obj;
    }

    /**
     * 支付同步通知处理
     */
    public function page_callback($params,$order) {
        header("Location:" . url('/orderquery',['orderid'=>$order->trade_no]));
    }

    /**
     * 支付异步通知处理
     */
    public function notify_callback($params,$order) {
        if($params['out_trade_no']) {
            $buff = "";
            foreach ($params as $k => $v) {
                if ($k != "sign" && $v != "" && !is_array($v)) {
                    $buff .= $k . "=" . $v . "&";
                }
            }
            $buff = trim($buff, "&");
            $string = $buff . "&key=" . $this->account->params->signkey;
            $string = md5($string);
            $sign = strtoupper($string);
            if ($sign != $params["sign"]) {
                record_file_log('wxpay_notify_error','验签错误！'."\r\n".$order->trade_no);
                die('验签错误！');
            }
            // 金额异常检测
            $money=$params['total_fee']/100;
            if($order->total_price>$money){
                record_file_log('alipay_notify_error','金额异常！'."\r\n".$order->trade_no."\r\n订单金额：{$order->total_price}，已支付：{$money}");
                die('金额异常！');
            }
            // TODO 这里去完成你的订单状态修改操作
            // 流水号
            $order->transaction_id =$params['transaction_id'];
            $this->completeOrder($order);
            record_file_log('wxpay_notify_success',$order->trade_no);
            echo 'success';
            return true;
        }
    }

    /**
     * 支付宝当面付异步回调数据验签
     * @param  array $params                待验证数据
     * @param  string $alipay_public_key    支付宝应用公钥
     * @param  string $sign_type            秘钥类型
     * @return boolean                      验签状态
     */
    private function verify_sign($params,$alipay_public_key,$sign_type='RSA2')
    {
        $ori_sign=$params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        $data='';
        foreach($params as $k => $v){
            $data.=$k.'='.$v.'&';
        }
        $data=substr($data,0,-1);
        $public_content="-----BEGIN PUBLIC KEY-----\n" . wordwrap($alipay_public_key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $public_key=openssl_get_publickey($public_content);
        if($public_key){
            if($sign_type=='RSA2') {
                $result = (bool)openssl_verify($data, base64_decode($ori_sign), $public_key, OPENSSL_ALGO_SHA256);
            } else {
                $result = (bool)openssl_verify($data, base64_decode($ori_sign), $public_key);
            }
            openssl_free_key($public_key);
            return $result;
        }else{
            return false;
        }
    }
    function createNoncestr( $length = 32 ){
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str ="";
        for ( $i = 0; $i < $length; $i++ )  {
            $str.= substr($chars, mt_rand(0, strlen($chars)-1), 1);
        }
        return $str;
    }
    function postXmlCurl($xml,$url,$second = 30){
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        }else{
            $error = curl_errno($ch);
            curl_close($ch);
            return "Error";
        }
    }
    function get_client_ip() {
        if(getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
            $ip = getenv('HTTP_CLIENT_IP');
        } elseif(getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
            $ip = getenv('HTTP_X_FORWARDED_FOR');
        } elseif(getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
            $ip = getenv('REMOTE_ADDR');
        } elseif(isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
    private function defineWxConfig($pargrm) {
        if (!defined('wx_APPID')) {
            define('wx_APPID', $pargrm->APPID);
        }
        if (!defined('wx_MCHID')) {
            define('wx_MCHID', $pargrm->MCHID);
        }
        if (!defined('wx_SUBAPPID')) {
            define('wx_SUBAPPID', @$pargrm->sub_appid);
        }
        if (!defined('wx_SUBMCHID')) {
            define('wx_SUBMCHID', @$pargrm->sub_mch_id);
        }
        if (!defined('wx_KEY')) {
            define('wx_KEY', $pargrm->KEY);
        }
        if (!defined('wx_APPSECRET')) {
            define('wx_APPSECRET', $pargrm->APPSECRET);
        }
    }
}
