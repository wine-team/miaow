 <?php
class Mall_address_model extends CI_Model
{
    private $table   = 'mall_address';
    
     /**
     * 发现地址
     * @param unknown $param
     */
    public function findAddressByRes($param=array(),$f='*',$limit=1){
    	
    	$this->db->select($f);
    	if(!empty($param['uid'])) {
    		$this->db->where('uid',$param['uid']);
    	}
    	$this->db->order_by('is_default','desc'); // 2为默认  1为非默认
    	$this->db->limit($limit);
    	return $this->db->get($this->table);
    }

     /**
     * 插入
     * @param unknown $param
     */
    public function insert($param) {
    	
       $this->db->insert($this->table,$param);
       return $this->db->insert_id();
    }
}