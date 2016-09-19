<?php
class Pay extends CS_Controller {

	public function _init() {
		
		$this->load->library('encrypt');
		$this->load->library('chinapay/chinapay', null, 'chinapay');
		$this->load->library('alipay/alipaypc', null, 'alipaypc');
		$this->load->model('mall_goods_base_model','mall_goods_base');
		$this->load->model('mall_order_pay_model','mall_order_pay');
		$this->load->model('mall_order_base_model','mall_order_base');
		$this->load->model('mall_order_product_model','mall_order_product');
	}

	/**
	 * 网银去支付方法。
	 */
	public function grid()
	{
		$pay_id = $this->input->post('pay_id');
		$pay_bank = $this->input->post('pay_bank');
		$result = $this->mall_order_pay->findOrderPayByRes(array('uid'=>$this->uid,'pay_id'=>$pay_id));
		if ($result->num_rows() <= 0) {
			$this->alertJumpPre('订单信息不对');
		}
		$orderInfo = $result->row(0);
		switch ($pay_bank) {
			case '2' :  //微信支付
				$data['orderInfo'] = $orderInfo;
				$this->load->view('payment/balancePay', $data);
				break;
			case '3' :  //银联支付
				$BgRetUrl = site_url('paycallback/chinapayReturn');
				$PageRetUrl = site_url('paycallback/chinapayReturn');
				$objPay = $this->chinapay->callChinapayApi($pay_id, $orderInfo->order_amount, 'notcart', $BgRetUrl, $PageRetUrl);
				break;
			default :   //支付宝支付
				$alipayParameter = $this->alipayParameter($pay_bank, $orderInfo);
				$this->alipaypc->callAlipayApi($alipayParameter);
				break;
		}
	}
	
	
	/**
	 * 获取支付宝需要参数。
	 * @param paybank $bank_id
	 * @param object $orderInfo
	 * @param object $orderProductInfo    ---主订单号的
	 * @return array
	 */
	private function alipayParameter($pay_bank, $orderInfo)
	{
		$parameter = array(
				'out_trade_no' => $orderInfo->pay_id,
				'subject'      => $orderInfo->pay_id,
				'total_fee'    => $orderInfo->order_amount,
				'body'         => $orderInfo->pay_id,
				'show_url'     => base_url(),
				'notify_url'   => base_url('paycallback/alipayNotify'),
				'return_url'   => base_url('payt/alipayReturn'),
				'pay_method'   => $pay_bank,
				'defaultbank'  => 'alipay'
		);
		return $parameter;
	}
	
	 /**
	 * 获取订单是否支付
	 */
	public function getOrderStatus() {
		
		$pay = $this->input->post('pay');
		if (empty($pay)) {
			$this->jsen('非法参数');
		}
		$payId = base64_decode($pay);
		$mainRes = $this->mall_order_pay->findOrderPayByRes(array('uid'=>$this->uid,'pay_id'=>$payId));
		if ($mainRes->num_rows()<=0) {
			$this->jsen('主订单不存在');
		}
		$mainOrder = $mainRes->row(0);
		if ($mainOrder->status==2) {
			$this->jsen(site_url('pay/complete?pay='.$pay),true);
		}
		$this->jsen('该订单没有支付');
	}
	
	 /**
	 *支付完成区分结果
	 */
	public function complete() {
		
		$pay = $this->input->get('pay');
		if (empty($pay)) {
			$this->alertJumpPre('非法参数');
		}
		$orderMainSn = base64_decode($pay);
		$mainRes = $this->mall_order_pay->findOrderPayByRes(array('uid'=>$this->uid,'order_main_sn'=>$orderMainSn));
		if ($mainRes->num_rows()<=0) {
			$this->alertJumpPre('主订单不存在');
		}
		$data['mainOrder'] = $mainRes->row(0);
		$orderRes = $this->mall_order_base->getOrderBaseByRes(array('uid'=>$this->uid,'order_main_sn'=>$orderMainSn));
		if ($orderRes->num_rows()<=0) {
			$this->alertJumpPre('订单不存在');
		}
		$data['order'] = $orderRes->row(0);
		$productRes = $this->mall_order_product->getOrderProduct(array('uid'=>$this->uid,'order_main_sn'=>$orderMainSn));
		if ($productRes->num_rows()<=0) {
			$this->alertJumpPre('订单产品表不存在');
		}
		$data['orderProduct'] = $productRes->result();
		$this->load->view('payment/complete',$data);
	}
}