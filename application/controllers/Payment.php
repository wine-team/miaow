<?php
class Payment extends CS_Controller {

	public function _init() {
		
		$this->load->library('encrypt');
		$this->load->library('qrcode',null,'QRcode');
		$this->load->model('user_model','user');
		$this->load->model('region_model','region');
		$this->load->model('account_log_model','account_log');
		$this->load->model('mall_address_model','mall_address');
		$this->load->model('mall_cart_goods_model','mall_cart_goods');
		$this->load->model('mall_goods_base_model','mall_goods_base');
		$this->load->model('mall_order_pay_model','mall_order_pay');
		$this->load->model('mall_order_base_model','mall_order_base');
		$this->load->model('mall_order_product_model','mall_order_product');
		$this->load->model('mall_freight_tpl_model','mall_freight_tpl');
		$this->load->model('mall_freight_price_model','mall_freight_price');
		$this->load->model('user_coupon_get_model','user_coupon_get');
		$this->load->model('mall_order_product_profit_model','mall_order_product_profit');
	}

	 /**
	  *首页
	 */
     public function create_order(){
     	
     	$postData = $this->input->post();
     	$this->validate($postData);
     	$jf = $this->input->post('jf');
     	$addressId = $this->input->post('address_id');
     	$couponId = $this->input->post('coupon_id');
     	$deliveryArray = $this->getAddress($addressId,$postData);
        if (empty($deliveryArray)) {
        	$this->jsen('收货地址出错');
        }
     	$goods = $this->encryptGoods($postData['goods'],$deliveryArray['area']);// 检验商品数量和限制购买数量
     	if (empty($goods)) {
     		$this->jsen('产品生成出错');
     	}
     	$subtotal = 0;
     	$overrPoints = 0;// 剩余积分
     	$payId = $this->getOrderSn(); //主订单编号
     	$userPoints = $this->user->getPayPoints($this->uid); // 用户总积分
     	$orderParam['pay_bank'] = isset($postData['pay_bank']) ? $postData['pay_bank'] : 1;
     	$orderParam['order_note'] = isset($postData['order_note']) ? $postData['order_note'] : '';
     	$orderParam['delivery_address'] = $deliveryArray['deliver'];
     	$this->db->trans_begin();
     	
     	foreach ($goods['order'] as $key => $item) {
     		$transport_cost = $item['sub'];
     		$orderShopPrice = 0; // 订单销售价
     		$orderActualPrice = 0; // 订单实际支付价
     		$orderSupplyPrice = 0; // 订单供应价
     		$orderIntegral = 0;    // 产品积分
     		$orderActualIntegral = 0; // 每个订单实际支付积分
     		$orderActualCoupn = 0 ;// 优惠劵实际抵扣
     		$order_id = $this->create_mall_order($key, $item, $payId, $orderParam);
     		if (!$order_id) {
     			$this->db->trans_rollback();
     			$this->jsen('生成订单失败');
     		}
     		foreach ($item['goods'] as $k => $val) {
     			$order_product_id = $this->creat_order_product($val,$order_id); //订单产品表
     			if (!$order_product_id) {
     				$this->db->trans_rollback();
     				$this->jsen('生成订单产品失败');
     			}
     			$mallProfit = $this->creat_order_profit($order_product_id,$val); //订单分润数据
     			if (!$mallProfit) {
     				$this->db->trans_rollback();
     				$this->jsen('订单分润失败');
     			}
     			$orderShopPrice += bcmul($val->goods_num,$val->total_price,2);
     			$orderSupplyPrice += bcmul($val->goods_num,$val->provide_price,2);
     			$orderActualPrice += bcmul($val->goods_num,$val->total_price,2);
     			$orderIntegral += bcmul($val->goods_num,$val->integral,2);
     			
     			$paramsCart['uid'] = $this->uid;
     			$paramsCart['goods_id'][] = $val->goods_id;
     			
     			if ($val->minus_stock ==1) { // 拍下减库存
     				$numStatus = $this->mall_goods_base->setMallNum(array('goods_id'=>$val->goods_id,'number'=>$val->goods_num)); // 产品表库存的变化
     				if (!$numStatus) {
     					$this->db->trans_rollback();
     					$this->jsen('更新库存失败');
     				}
     			}
     		}
     		
     		if (!empty($jf)) {  //优惠劵的使用
     			$orderActualIntegral = $this->getIntegral($orderIntegral,$userPoints);// 获取实际抵扣积分
     			$order_update_params['integral'] = $orderActualIntegral;
     			if ($orderActualIntegral != 0) {
     				$userStatus = $this->user->setPayPoints($orderActualIntegral,$this->uid);
     				$logStatus = $this->insertLog($order_id,$orderActualIntegral);//积分使用记录
     				if (!$userStatus || !$logStatus) {
     					$this->db->trans_rollback();
     					$this->jsen('更新账户积分失败');
     				}
     			}
     			$userPoints = bcsub($userPoints,$orderActualIntegral);//剩余积分
     		}
     		
     	    if (!empty($couponId)) { //积分的使用
     	   	   
     	        $couponRes = $this->user_coupon_get->getCouponById($couponId,$this->uid);
     	        if ($couponRes->num_rows()<=0) {
     	        	$this->jsen('优惠劵不存在');
     	        }
     	        $coupon = $couponRes->row(0);
     	        if ( (bcsub(bcsub($orderActualPrice,$coupon->amount,2),$orderActualIntegral/100,2)>=0) && ($coupon->status==1) ) {
     	        	$orderActualCoupn = $coupon->amount;
     	        	$this->user_coupon_get->updateStatus($couponId,$this->uid);
     	        }
     	    }

     		$updateOrder = $this->updateMallOrder($order_id,$couponId,$orderSupplyPrice,$orderShopPrice,$orderActualPrice,$orderActualIntegral,$orderActualCoupn);
     	    if (!$updateOrder) {
     	    	$this->db->trans_rollback();
     	    	$this->jsen('更新订单失败');
     	    }
     	    $subtotal += bcsub(bcsub(bcadd($orderActualPrice, $transport_cost, 2),$orderActualIntegral/100,2),$orderActualCoupn,2); //所有订单总价
     	}
     	$main_order = $this->creat_main_order($payId,$subtotal,$orderParam['pay_bank']);
     	if (!$main_order) {
     		$this->db->trans_rollback();
     		$this->jsen('主订单生成失败');
     	}
     	$this->mall_cart_goods->clear_cart($paramsCart);//清除购物车已经生成订单的产品
     	if ($this->db->trans_status() === FALSE) {
     		$this->db->trans_rollback();
     		$this->jsen('订单生成失败');
     	}
     	$this->db->trans_commit();
        $mainOrder = base64_encode($payId); //加密
        $this->jsen(site_url('payment/order?pay='.$mainOrder),true);
     }
     
      /**
      *订单支付页面
      */
     public function order() {
     	
     	$pay = $this->input->get('pay');
     	if (empty($pay)) {
     	 	$this->alertJumpPre('非法参数');
     	}
     	$payId = base64_decode($pay);
     	$mainRes = $this->mall_order_pay->findOrderPayByRes(array('uid'=>$this->uid,'pay_id'=>$payId));
     	if ($mainRes->num_rows()<=0) {
     		$this->alertJumpPre('主订单不存在');
     	}
     	$data['mainOrder'] = $mainRes->row(0);
     	$orderRes = $this->mall_order_base->getOrderBaseByRes(array('uid'=>$this->uid,'pay_id'=>$payId));
     	if ($orderRes->num_rows()<=0) {
     		$this->alertJumpPre('订单不存在');
     	}
     	$data['order'] = $orderRes->row(0);
     	$productRes = $this->mall_order_product->getOrderProduct(array('uid'=>$this->uid,'pay_id'=>$payId));
     	if ($productRes->num_rows()<=0) {
     		$this->alertJumpPre('订单产品表不存在');
     	}
     	$data['orderProduct'] = $productRes->result();
     	$data['pay_method'] = array('1'=>'支付宝','2'=>'微信','3'=>'银联');
     	if ($data['mainOrder']->pay_bank == 2) { 
     		$data['payEwm'] = $this->productEwm($payId);
     		$this->load->view('payment/wxpay',$data);
     	} else {
     		$this->load->view('payment/grid',$data);
     	}
     }
     
     /**
      * 更新主库的订单信息
      * @param unknown $order_id
      * @param unknown $couponId
      * @param unknown $orderSupplyPrice
      * @param unknown $orderShopPrice
      * @param unknown $orderActualPrice
      * @param unknown $orderActualIntegral
      * @param unknown $orderActualCoupn
      */
     private function updateMallOrder($order_id,$couponId,$orderSupplyPrice,$orderShopPrice,$orderActualPrice,$orderActualIntegral,$orderActualCoupn) {
     	
     	$actual_price =  bcsub(bcsub($orderActualPrice,$orderActualIntegral/100,2),$orderActualCoupn,2);
     	$order_update_params['order_id'] = $order_id;
     	$order_update_params['coupon_code'] = $couponId;
     	$order_update_params['order_status'] = 2;
     	$order_update_params['order_supply_price'] = $orderSupplyPrice;// 实际供应价
     	$order_update_params['order_shop_price'] = $orderShopPrice;// 实际销售价
     	$order_update_params['actual_price'] = $actual_price;// 实际支付价
     	$order_update_params['order_pay_price'] = $actual_price; // 实际支付价
     	$order_update_params['coupon_price'] = $orderActualCoupn;
     	return $this->mall_order_base->updateMallOrder($order_update_params);//订单表的修改
     }
     
     /**
      * 获取可抵积分
      * @param unknown $orderIntegral  --一个订单表的需要抵扣积分
      * @param unknown $userPoints -- 用户账户积分
      */
     private function getIntegral($orderIntegral,$userPoints) {
     	
     	if ($orderIntegral == 0) {
     		return $orderIntegral;
     	}
     	if ($userPoints == 0) {
     		return $userPoints;
     	}
     	if ($userPoints < $orderIntegral) {
     		return $userPoints;
     	}
     	if ($orderIntegral < $userPoints) {
     		return $orderIntegral;
     	}
     }
     
     /**
      * 使用日志
      * @param unknown $order_id
      * @param unknown $orderActualIntegral
      */
     private function insertLog($order_id,$orderActualIntegral) {
     	
     	$param = array(
     		'uid' => $this->uid,
     		'order_id' => $order_id,
     		'account_type' => 2,//1账户,2积分 
     		'flow' => 2, // 1收入，2支出
     		'trade_type' => 1,//1购物，2充值，3提现，4转账，5还款,6退款
     		'amount' => bcdiv($orderActualIntegral,100,2),
     		'note' => '订单号为：'.$order_id.' 使用  '.$orderActualIntegral.'积分'
     	);
     	return $this->account_log->insertLog($param);
     }
     
      /**
      * 二维码的生产
      * @param unknown $attr_id
      */
     public function productEwm($payId){
     		
     	$url = $this->config->m_url.'pay/wxPay?pay='.base64_encode($payId).'.html';
     	$name = 'pay-'.$payId.'.png';
     	$path = $this->config->upload_image_path('common/ewm').$name;
     	$this->QRcode->png($url,$path,4,10);
     	return $name;
     }

      /**
      * 创建主订单
      * @param unknown $orderMainSn
      * @param unknown $subtotal
      * @param unknown $pay_bank
      */
     public function creat_main_order($payId,$subtotal,$pay_bank) {
     	
     	$main_params['uid'] = $this->uid;
     	$main_params['pay_bank'] = $pay_bank; //支付银行
     	$main_params['pay_id'] = $payId;
     	$main_params['created_at'] = date('Y-m-d H:i:s');
     	$main_params['order_amount'] = $subtotal;
     	return $this->mall_order_pay->create_order($main_params); //插入总订单
     }
     
      /**
      * 订单分润数据的插入
      * @param unknown $order_product_id
      * @param unknown $val
      */
     public function creat_order_profit($order_product_id,$val){
     	
     	$param['order_product_id'] = $order_product_id;
     	$param['uid'] = $val->supplier_id;
     	$param['account'] = bcmul($val->goods_num,$val->provide_price,2);
     	$param['account_type'] = 1;
     	$param['as'] = 1;
     	return $this->mall_order_product_profit->insertOrderProfit($param);
     }
     
      /**
      * 生成订单
      * @param unknown $key
      * @param unknown $item
      * @param unknown $orderMainSn
      * @param unknown $orderParam
      */
     public function create_mall_order($key, $item, $payId, $orderParam) {
     	
     	$params['pay_id'] = $payId;
     	$params['order_state'] = 1;
     	$params['order_status'] = 1;
     	$params['seller_uid'] = $key;
     	$params['payer_uid'] = $this->uid;
     	$params['user_name'] = $this->aliasName;
     	$params['pay_method'] = 1;
     	$params['pay_bank'] = $orderParam['pay_bank'];
     	$params['deliver_order_id'] = 0;
     	$params['delivery_address'] = $orderParam['delivery_address'];
     	$params['deliver_price'] = $item['sub'];
     	$params['order_note'] = $orderParam['order_note'];
     	$params['is_from'] = 1;
     	$params['created_at'] = date('Y-m-d H:i:s');
     	return $this->mall_order_base->create_order($params);
     }
     
      /**
      * 创建订单产品表
      * @param unknown $val
      * @param unknown $order_id
      */
     public function creat_order_product($val,$order_id) {
     	
     	$param['order_id'] = $order_id;
     	$param['goods_id'] = $val->goods_id;
     	$param['goods_name'] = $val->goods_name;
     	$param['attr_value'] = $val->attribute_value;
     	$param['goods_img'] = $val->goods_img;
     	$param['extension_code'] = $val->extension_code;
     	$param['number']  = $val->goods_num;
     	$param['barter_num'] = 0;
     	$param['refund_num'] = $val->goods_num;
     	$param['market_price'] = $val->market_price; //市场价
     	$param['shop_price'] = $val->total_price;// 贝竹价
     	$param['supply_price'] = $val->provide_price; // 供应价
     	$param['integral'] = 0 ; //可用积分
     	$param['pay_amount'] = $val->total_price;// 实际支付价
     	$param['created_at'] = date('Y-m-d H:i:s');
     	return $this->mall_order_product->addOrderProduct($param);
     }
     
      /**
      * 插入地址
      * @param unknown $postData
      * @return string
      */
     public function getAddress($addressId,$postData) {
     	
     	$regionids = array($postData['province_id'], $postData['city_id'],$postData['district_id']);
     	$region = $this->region->getByRegionIds($regionids);
     	if ($region->num_rows() < 3) {
     		$this->jsen('城市地区请填写完整');
     	}
     	$regionNames = array();
     	foreach ($region->result() as $item) {
     		$regionNames[] = $item->region_name;
     	}
     	if (empty($addressId)) {
     		$param['uid'] = $this->uid;
     		$param['province_id'] = $postData['province_id'];
     		$param['province_name'] = $regionNames[0];
     		$param['city_id'] = $postData['city_id'];
     		$param['city_name'] =  $regionNames[1];
     		$param['district_id'] = $postData['district_id'];
     		$param['district_name'] = $regionNames[2];
     		$param['detailed'] = htmlspecialchars($postData['detailed']);
     		$param['code'] = isset($postData['code']) ? $postData['code'] : '000000';
     		$param['receiver_name'] = $postData['receiver_name'];
     		$param['tel'] = $postData['tel'];
     		$param['is_default'] = 2;
     		$addressId = $this->mall_address->insert($param);
     	}
     	$deliver = array( 
     			'receiver_name' => $postData['receiver_name'],
     			'detailed'      => $regionNames[0] .' '.$regionNames[1].' '.$regionNames[2].' '.$postData['detailed'],
     			'tel'           => $postData['tel'],
     	);
     	return array('deliver'=>json_encode($deliver),'area'=>$regionNames[0]);
     }
     /**
      * 校验产品数量
      * @param unknown $goods
      */
     public function encryptGoods($goods,$area) {
     	
     	$goodsIdArr = array_keys($goods);
     	$goodsRes = $this->mall_cart_goods->getCartGoodsByRes(array('uid'=>$this->uid,'goods_id'=>$goodsIdArr));
     	if ($goodsRes->num_rows()<=0) {
     		$this->jsen('产品不存在');
     	}
     	$total = 0; // 订单销售价
     	$argc = array();
     	foreach ($goodsRes->result() as $item) {
     		if ($item->in_stock<=0) {
     			$this->jsen('商品' . $item->goods_name . '库存为零');
     		} 
     		if ($goods[$item->goods_id] > $item->in_stock) {
     			$this->jsen('商品' . $item->goods_name . '库存不足，最多可购买'. $item->in_stock . '件' );
     		}
     		if ($item->limit_num>0) {
     			if ($goods[$item->goods_id] > $item->limit_num ) {
     				$this->jsen('商品' . $item->goods_name . '（一个用户限购' . $item->limit_num . '件）');
     			}
     		}
     		$item->goods_num = $goods[$item->goods_id]; //购买产品的数量
     		$item->total_price = $this->getTotalPrice($item); // 实际销售价因为促销价在里面
     		$total += bcmul($item->goods_num,$item->total_price,2);
     		/**订单数据的处理**/
     		$supplier_id = $item->supplier_id;
     		$argc[$supplier_id]['supplier_id'] = $supplier_id;
     		$argc[$supplier_id]['goods'][] = $item;
     	}
     	$argc = $this->getFreight($argc,$area,$total);
     	return array(
     		  'order' => $argc,
     		  'total' => $total  //总价多少钱
     	);
     }
     
     /**
      * 获取实际价格  促销价和妙处网销售价
      * @param unknown $val
      */
     private function getTotalPrice($val) {
     
     	if( !empty($val->promote_price) && !empty($val->promote_start_date) && !empty($val->promote_end_date) && ($val->promote_start_date<=time()) && ($val->promote_end_date>=time())) {
     		$total_price =  $val->promote_price;
     	} else {
     		$total_price = $val->shop_price;
     	}
     	return $total_price;
     }
     
     /**
      * 获取运维信息
      * @param unknown $cartArr
      */
     public function getFreight($goods,$area,$totalPrice) {
     	 
     	$freight = array(); //获取商品是哪个模板 哪个地区 是否是
     	if (bcsub($totalPrice,99,2)>=0) { //满99元包邮
     		foreach ($goods as $key => $item) {
     			$goods[$key]['sub'] = 0; // 每个商品运费为零
     		}
     		return $goods;
     	}
     	foreach ($goods as $key => $item) {
     		foreach ($item['goods'] as $val) {//循环店铺
     			$tid = $val->freight_id;
     			$freight[$key][$tid]['supplier_id'] = $val->supplier_id;
     			if ($tid==0) {
     				//没有使用模板 使用默认金额
     				$freight[$key][$tid]['total_qty'][] = $val->goods_num;
     				$freight[$key][$tid]['freight_cost'] = $val->freight_cost;
     			} else {
     				//计算总件数
     				$freight[$key][$tid]['total_qty'][] = $val->goods_num;
     				//计算总重量
     				$freight[$key][$tid]['total_weight'][] = $val->goods_num * $val->goods_weight;
     				$freight[$key][$tid]['total_price'][] = bcmul($val->goods_num,$val->promote_price,2);
     			}
     		}
     	}
     	foreach ($freight as $key => $items) {
     
     		$sub = 0;
     		foreach ($items as $freight_id => $item) {  //店铺下运费模版的计算
     			if ($freight_id == 0) {
     				$sub += $item['freight_cost'];
     				continue;
     			}
     			$result = $this->mall_freight_tpl->getTransports(array('freight_id'=>$freight_id,'uid'=>$item['supplier_id']));
     			if (isset($result->methods)) {
     				//根据收货地址获取模板计算规则
     				$param['area'] = $area;
     				$param['freight_id'] = $freight_id;
     				$transport = $this->mall_freight_price->getFreightRow($param);
     				if ($result->methods == 1) { //按件计算
     					$total_qty = 0;
     					foreach ($item['total_qty'] as $freight_val) {
     						$total_qty += $freight_val; //总件数
     					}
     					if ($transport->add_unit == 0) {
     						$sub += $transport->first_price;
     					} else {
     						if ($total_qty <= $transport->first_unit) {//总件数小于首件
     							$sub += $transport->first_price;
     						} else {
     							//计算超出部分
     							$over_unit = $total_qty - $transport->first_unit;
     							//超出部分价格
     							$over_price = ceil($over_unit / $transport->add_unit) * $transport->add_price;
     							$sub += $over_price + $transport->first_price;
     						}
     					}
     				}
     				if ($result->methods == 2) { //按重量计算
     						
     					$total_weight = 0;
     					$total_price = 0;
     					foreach ($item['total_weight'] as $freight_val) {
     						$total_weight += $freight_val;// 总重量
     					}
     					if ($transport->add_unit == 0) {
     						$sub += $transport->first_price;
     					} else {
     						if ($total_weight <= $transport->first_unit) {//重量小于首重
     							$sub += $transport->first_price;
     						} else {//重量大于首重
     							//计算超出部分
     							$over_weight = $total_weight - $transport->first_unit;
     							//超出部分价格
     							$over_price = ceil($over_weight / $transport->add_unit) * $transport->add_price;
     							$sub += $over_price + $transport->first_price;
     						}
     					}
     				}
     			}
     		}
     		$goods[$key]['sub'] = $sub; //每个供应商下的产品的运费
     	} 
     	return $goods;
     }
     
      /**
      * 订单唯一的序列编号
      * @return string
      */
     public function getOrderSn() {
     	
     	return date('ynjGis') . mt_rand(100, 999);
     }
      
      /**
      * 订单验证
      * @param unknown $postData
      */
     public function validate($postData){
     	
     	if (empty($this->uid)) {
     		$this->jsen('请先登录');
     	}
     	if (empty($postData['goods']) || !is_array($postData['goods'])) {
     		$this->jsen('快去选购产品哦');
     	}
     	if (empty($postData['receiver_name'])) {
     		$this->jsen('请填收货姓名');
     	}
     	if (empty($postData['tel'])) {
     		$this->jsen('请填联系方式');
     	}
     	if (!valid_mobile($postData['tel'])) {
     		$this->jsen('请填正确的手机号码');
     	}
     	if (empty($postData['province_id'])) {
     		$this->jsen('请选择省份');
     	}
     	if (empty($postData['city_id'])) {
     		$this->jsen('请选择市');
     	}
     	if (empty($postData['district_id'])) {
     		$this->jsen('请选择区');
     	}
     	if (empty($postData['detailed'])) {
     		$this->jsen('请填详细地址');
     	}
     	if (empty($postData['pay_bank'])) {
     		$this->jsen('请选择支付方式');
     	}
     }
}