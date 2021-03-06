<?php
class Mall_order_base_model extends CI_Model
{
    private $table   = 'mall_order_base';
    
     /**
     * 获取订单列表
     */
    public function getMallOrderProduct($param=array()) {
    	
    	$this->db->select('mall_order_base.order_id,mall_order_base.pay_id,mall_order_base.order_state,
    					   mall_order_base.order_status,mall_order_base.user_name,mall_order_base.pay_method,
    			           mall_order_base.pay_bank,mall_order_base.deliver_price,mall_order_base.order_supply_price,
    			           mall_order_base.order_shop_price,mall_order_base.actual_price,mall_order_base.order_pay_price,mall_order_base.created_at,
    			           mall_order_product.goods_id,mall_order_product.goods_name,mall_order_product.attr_value,
    			           mall_order_product.goods_img
    			         ');
    	$this->db->from($this->table);
    	$this->db->join('mall_order_product','mall_order_base.order_id=mall_order_product.order_id');
        $this->db->where('mall_order_base.payer_uid',$param['uid']);
        $this->db->group_by('mall_order_product.order_id');
        $this->db->order_by('mall_order_product.order_id','desc');
        return $this->db->get();
    }
    
    /**
     * 获取
     */
    public function getMallOrder($param=array(),$f='*') {
    	
    	$this->db->select($f);
    	$this->db->from($this->table);
    	$this->db->where('order_id',$param['order_id']);
    	$this->db->where('payer_uid',$param['payer_uid']);
    	return $this->db->get();
    }
    
    /**
     * 更新时间
     * @param unknown $param
     * @param unknown $order_id
     */
    public function update($param=array(),$order_id) {
    	
    	if (!empty($param['order_status'])) {
    		$data['order_status'] = $param['order_status'];
    	}
    	if (!empty($param['order_state'])) {
    		$data['order_state'] = $param['order_state'];
    	}
    	$data['updated_at'] = date('Y-m-d H:i:s');
    	
    	$this->db->where('order_id',$order_id);
    	return $this->db->update($this->table,$data);
    }
    
     /**
     * 更新订单
     * @param unknown $param
     */
    public function updateMallOrder($param)
    {
    	$this->db->where('order_id', $param['order_id']);
    	return $this->db->update($this->table, $param);
    }
    
    /**
     * 根据pay_id更新内容
     * @param unknown $pay_id
     * @param unknown $param
     */
    public function updateByPayId($pay_id,$param) {
    	 
    	$this->db->where('pay_id',$pay_id);
    	return $this->db->update($this->table,$param);
    }
    
    /**
     * 生产订单
     * @param unknown $params
     */
    public function create_order($params)
    {
    	$this->db->insert($this->table, $params);
    	return $this->db->insert_id();
    }
    
    /**
     * 获取订单信息
     * @param unknown $param
     */
    public function getOrderBaseByRes($param=array()){
    	 
    	$this->db->select('SUM(deliver_price) AS transport_cost,SUM(actual_price) AS actual_pay,delivery_address');
    	$this->db->from($this->table);
    	if (!empty($param['uid'])) {
    		$this->db->where('payer_uid',$param['uid']);
    	}
    	if (!empty($param['pay_id'])) {
    		$this->db->where('pay_id',$param['pay_id']);
    	}
    	return $this->db->get();
    }
    
    public function getOrderTotal($param=array()) {
    	
    	if (!empty($param['uid'])) {
    		$this->db->where('payer_uid',$param['uid']);
    	}
    	return $this->db->count_all_results($this->table);
    }
    
}