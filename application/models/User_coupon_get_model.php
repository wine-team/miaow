<?php
class User_coupon_get_model extends CI_Model
{
	private $table = 'user_coupon_get';
	
	 /**
	 * 获取优惠劵
	 * @param unknown $uid
	 */
	public function getCouponByRes($param=array()) {
		
		$this->db->select('coupon_get_id,coupon_set_id,coupon_name,uid,amount,condition');
		$this->db->from($this->table);
	    $this->db->where('user_coupon_get.status',1);
	    $this->db->where('user_coupon_get.start_time<=',date('Y-m-d H:i:s'));
	    $this->db->where('user_coupon_get.end_time>=',date('Y-m-d H:i:s'));
	    if (isset($param['uid'])) {
	    	$this->db->where('user_coupon_get.uid',$param['uid']);
	    }
	    if (isset($param['condition'])) {
	    	$this->db->where('user_coupon_get.condition <= ',$param['condition']);
	    }
	    $result = $this->db->get();
	    $couponArr = array();
	    if ($result->num_rows()>0) {
	    	foreach ($result->result() as $key=>$item) {
	    		$couponArr[$item->coupon_get_id] = $item;
	    	}
	    }
	    return  $couponArr;
	}
	
}