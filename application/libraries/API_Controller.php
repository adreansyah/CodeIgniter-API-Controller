<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter API Controller
 *
 * @package         CodeIgniter
 * @subpackage      Libraries
 * @category        Libraries
 * @author          Jeevan Lal
 * @license         MIT
 * @link            
 * @version         1.0.0
 */
class API_Controller extends CI_Controller
{
    /**
     * List of allowed HTTP methods
     *
     * @var array
     */
    protected $allowed_http_methods = ['get', 'delete', 'post', 'put', 'options', 'patch', 'head'];

    /**
     * The request method is not supported by the following resource
     * @link http://www.restapitutorial.com/httpstatuscodes.html
     */
    const HTTP_METHOD_NOT_ALLOWED = 405;
    const STR_METHOD_NOT_ALLOWED = 'HTTP/1.1 405 Method Not Allowed';

    /**
     * The request cannot be fulfilled due to multiple errors
     */
    const HTTP_BAD_REQUEST = 400;
    const STR_BAD_REQUEST = 'BAD REQUEST';

    /**
     * Request Timeout
     */
    const HTTP_REQUEST_TIMEOUT = 408;
    const STR_REQUEST_TIMEOUT = 'Request Timeout';

    /**
     * The requested resource could not be found
     */
    const HTTP_NOT_FOUND = 404;
    const STR_NOT_FOUND = 'NOT FOUND';

    /**
     * The user is unauthorized to access the requested resource
     */
    const HTTP_UNAUTHORIZED = 401;
    const STR_UNAUTHORIZED = 'UNAUTHORIZED';


    const API_LIMIT_TABLE_NAME = 'api_limit';
    const API_KEYS_TABLE_NAME = 'api_keys';
    
    public function __construct() {
        parent::__construct();
        date_default_timezone_set('Asia/Kolkata');
        $this->CI =& get_instance();

        // load api config file
        $this->CI->load->config('api');
    }


    public function _apiConfig($config = [])
    {
        // by default method `GET`
        if ((isset($config) AND empty($config)) OR empty($config['methods'])) {
            $this->_allow_methods(['GET']);
        } else {
            $this->_allow_methods($config['methods']);
        }

        // api limit function `_limit_method()`
        if(isset($config['limit']))
            $this->_limit_method($config['limit']);

        // api key function `_api_key()`
        if(isset($config['key']))
            $this->_api_key($config['key']);

        // print_r($config);
    }


    /**
     * Allow Methods
     * -------------------------------------
     * @param: {array} request methods
     */
    public function _allow_methods(array $methods)
    {
        $REQUEST_METHOD = $this->CI->input->server('REQUEST_METHOD', TRUE);

        // check request method in `$allowed_http_methods` array()
        if (in_array(strtolower($REQUEST_METHOD), $this->allowed_http_methods))
        {
            // check request method in user define `$methods` array()
            if (in_array(strtolower($REQUEST_METHOD), $methods) OR in_array(strtoupper($REQUEST_METHOD), $methods))
            {
                // allow request method
                return true;

            } else
            {
                // not allow request method
                $this->response(['status' => FALSE, 'error' => 'Unknown method'], self::HTTP_METHOD_NOT_ALLOWED, self::STR_METHOD_NOT_ALLOWED);
            }
        } else {
            $this->response(['status' => FALSE, 'error' => 'Unknown method'], self::HTTP_METHOD_NOT_ALLOWED, self::STR_METHOD_NOT_ALLOWED);
        }
    }


    /**
     * Limit Method
     * ------------------------
     * @param: {int} number
     * @param: {type} ip
     * 
     * Total Number Limit without Time
     * 
     * @param: {minute} time/everyday

     * Total Number Limit with Last {3,4,5...} minute
     * --------------------------------------------------------
     */
    public function _limit_method(array $data)
    {
        // check limit number
        if (!isset($data[0])) {
            $this->response(['status' => FALSE, 'error' => 'Limit Number Required'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
        }

        // check limit type
        if (!isset($data[1])) {
            $this->response(['status' => FALSE, 'error' => 'Limit Type Required'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
        }

        // check limit database table exists
        if (!$this->db->table_exists(self::API_LIMIT_TABLE_NAME)) {
            $this->response(['status' => FALSE, 'error' => 'Create Limit Database Table'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
        }

        $limit_num = $data[0]; // limit number
        $limit_type = $data[1]; // limit type

        $limit_time = isset($data[2])? $data[2]:''; // time minute

        if ($limit_type == 'ip')
        {
            $where_data_ip = [
                'uri' => $this->CI->uri->uri_string(),
                'class' => $this->CI->router->fetch_class(),
                'method' => $this->CI->router->fetch_method(),
                'ip_address' => $this->CI->input->ip_address(),
            ];

            $limit_query = $this->CI->db->get_where(self::API_LIMIT_TABLE_NAME, $where_data_ip);
            if ($this->db->affected_rows() >= $limit_num)
            {
                // time limit not empty
                if (isset($limit_time) AND !empty($limit_time))
                {
                    // if time limit `numeric` numbers
                    if (is_numeric($limit_time))
                    {
                        $limit_timestamp = time() - ($limit_time*60);
                        // echo Date('d/m/Y h:i A', $times);
    
                        $where_data_ip_with_time = [
                            'uri' => $this->CI->uri->uri_string(),
                            'class' => $this->CI->router->fetch_class(),
                            'method' => $this->CI->router->fetch_method(),
                            'ip_address' => $this->CI->input->ip_address(),
                            'time >=' => $limit_timestamp
                        ];
    
                        $time_limit_query = $this->CI->db->get_where(self::API_LIMIT_TABLE_NAME, $where_data_ip_with_time);
                        // echo $this->CI->db->last_query();
                        if ($this->db->affected_rows() >= $limit_num)
                        {
                            $this->response(['status' => FALSE, 'error' => 'This IP Address has reached the time limit for this method'], self::HTTP_REQUEST_TIMEOUT, self::STR_REQUEST_TIMEOUT);
                        } else
                        {
                            // insert limit data
                            $this->limit_data_insert();
                        }
                    }

                    // if time limit equal to `everyday`
                    if ($limit_time == 'everyday')
                    {
                        $this->CI->load->helper('date');

                        $bad_date = mdate('%d-%m-%Y', time());

                        $start_date = nice_date($bad_date, 'd/m/Y h:i A'); // {DATE} 12:00 AM
                        $end_date = nice_date($bad_date .' 12:00 PM', 'd/m/Y h:i A'); // {DATE} 12:00 PM
                        
                        $start_date_timestamp = strtotime($start_date);
                        $end_date_timestamp = strtotime($end_date);

                        $where_data_ip_with_time = [
                            'uri' => $this->CI->uri->uri_string(),
                            'class' => $this->CI->router->fetch_class(),
                            'method' => $this->CI->router->fetch_method(),
                            'ip_address' => $this->CI->input->ip_address(),
                            'time >=' => $start_date_timestamp,
                            'time <=' => $end_date_timestamp,
                        ];
    
                        $time_limit_query = $this->CI->db->get_where(self::API_LIMIT_TABLE_NAME, $where_data_ip_with_time);
                        // echo $this->CI->db->last_query();exit;
                        if ($this->db->affected_rows() >= $limit_num)
                        {
                            $this->response(['status' => FALSE, 'error' => 'This IP Address has reached the time limit for this method'], self::HTTP_REQUEST_TIMEOUT, self::STR_REQUEST_TIMEOUT);
                        } else
                        {
                            // insert limit data
                            $this->limit_data_insert();
                        }
                    }

                } else {
                    $this->response(['status' => FALSE, 'error' => 'This IP Address has reached limit for this method'], self::HTTP_REQUEST_TIMEOUT, self::STR_REQUEST_TIMEOUT);
                }

            } else
            {
                // insert limit data
                $this->limit_data_insert();
            }
        } else {
            $this->response(['status' => FALSE, 'error' => 'Limit Type Invalid'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
        }
    }

    /**
     * Limit Data Insert
     */
    private function limit_data_insert()
    {
        $this->CI->load->helper('api_helper');

        $insert_data = [
            'uri' => $this->CI->uri->uri_string(),
            'class' => $this->CI->router->fetch_class(),
            'method' => $this->CI->router->fetch_method(),
            'ip_address' => $this->CI->input->ip_address(),
            'time' => time(),
        ];

        insert(self::API_LIMIT_TABLE_NAME, $insert_data);
    }

    /**
     * API key
     */
    private function _api_key(array $key)
    {
        if (!isset($key[0])) {
            $api_key_type = 'header';
        } else {
            $api_key_type = $key[0];
        }

        if (!isset($key[1])) {
            $api_key = 'table';
        } else {
            $api_key = $key[1];
        }

        // api key type `Header`
        if (strtolower($api_key_type) == 'header')
        {
            $api_key_header_name = $this->config->item('api_key_header_name');

            // check api key header name in request headers
            $is_header = $this->exists_header($api_key_header_name); // return status and header value
            if (isset($is_header['status']) === TRUE)
            {
                $HEADER_VALUE = trim($is_header['value']);

                // if api key equal to `table`
                if ($api_key != "table")
                {
                    if ($HEADER_VALUE != $api_key) {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_UNAUTHORIZED, self::STR_UNAUTHORIZED);
                    }

                } else
                {
                    // check api key database table exists
                    if (!$this->db->table_exists(self::API_KEYS_TABLE_NAME)) {
                        $this->response(['status' => FALSE, 'error' => 'Create API Key Database Table'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
                    }

                    $where_key_data = [
                        'controller' => $this->CI->router->fetch_class(),
                        'api_key' => $HEADER_VALUE,
                    ];

                    $limit_query = $this->CI->db->get_where(self::API_KEYS_TABLE_NAME, $where_key_data);
                    if (!$this->db->affected_rows() > 0)
                    {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
                    } 
                }

            } else
            {
                $this->response(['status' => FALSE, 'error' => 'API Key Header Required'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
            }
        } else if (strtolower($api_key_type) == 'get') // // api key type `get`
        {
            // return status and header value `Content-Type`
            $is_header = $this->exists_header('Content-Type');
            if (isset($is_header['status']) === TRUE) {
                if ($is_header['value'] === "application/json")
                {
                    $stream_clean = $this->CI->security->xss_clean($this->CI->input->raw_input_stream);
                    $_GET = json_decode($stream_clean, true);
                }
            }

            $api_key_get_name = $this->config->item('api_key_get_name');
            
            $get_param_value = $this->CI->input->get($api_key_get_name, TRUE);
            if (!empty($get_param_value) AND is_string($get_param_value))
            {
                // if api key equal to `table`
                if ($api_key != "table")
                {
                    if ($get_param_value != $api_key) {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_UNAUTHORIZED, self::STR_UNAUTHORIZED);
                    }

                } else
                {
                    // check api key database table exists
                    if (!$this->db->table_exists(self::API_KEYS_TABLE_NAME)) {
                        $this->response(['status' => FALSE, 'error' => 'Create API Key Database Table'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
                    }

                    $where_key_data = [
                        'controller' => $this->CI->router->fetch_class(),
                        'api_key' => $get_param_value,
                    ];

                    $limit_query = $this->CI->db->get_where(self::API_KEYS_TABLE_NAME, $where_key_data);
                    if (!$this->db->affected_rows() > 0)
                    {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
                    } 
                }
            } else
            {
                $this->response(['status' => FALSE, 'error' => 'API Key GET Parameter Required'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
            }
        } else if (strtolower($api_key_type) == 'post') // // api key type `post`
        {
            // return status and header value `Content-Type`
            $is_header = $this->exists_header('Content-Type');
            if (isset($is_header['status']) === TRUE) {
                if ($is_header['value'] === "application/json")
                {
                    $stream_clean = $this->CI->security->xss_clean($this->CI->input->raw_input_stream);
                    $_POST = json_decode($stream_clean, true);
                }
            }

            $api_key_post_name = $this->config->item('api_key_post_name');
            
            $get_param_value = $this->CI->input->post($api_key_post_name, TRUE);
            if (!empty($get_param_value) AND is_string($get_param_value))
            {
                // if api key equal to `table`
                if ($api_key != "table")
                {
                    if ($get_param_value != $api_key) {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_UNAUTHORIZED, self::STR_UNAUTHORIZED);
                    }

                } else
                {
                    // check api key database table exists
                    if (!$this->db->table_exists(self::API_KEYS_TABLE_NAME)) {
                        $this->response(['status' => FALSE, 'error' => 'Create API Key Database Table'], self::HTTP_BAD_REQUEST, self::STR_BAD_REQUEST);
                    }

                    $where_key_data = [
                        'controller' => $this->CI->router->fetch_class(),
                        'api_key' => $get_param_value,
                    ];

                    $limit_query = $this->CI->db->get_where(self::API_KEYS_TABLE_NAME, $where_key_data);
                    if (!$this->db->affected_rows() > 0)
                    {
                        $this->response(['status' => FALSE, 'error' => 'API Key invalid'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
                    } 
                }
            } else
            {
                $this->response(['status' => FALSE, 'error' => 'API Key POST Parameter Required'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
            }

        } else {
            $this->response(['status' => FALSE, 'error' => 'API Key Parameter Required'], self::HTTP_NOT_FOUND, self::STR_NOT_FOUND);
        }
    }


    /**
     * Check Request Header Exists
     * @return ['status' => true, 'value' => value ]
     */
    private function exists_header($header_name)
    {
        $headers = apache_request_headers();
        foreach ($headers as $header => $value) {
            if($header === $header_name) {
                return ['status' => true, 'value' => $value ];
            }
        }
    }

    
    public function response($data = NULL, $http_code = NULL, $http_string = NULL)
    {
        ob_start();

        header($http_string, true, $http_code);
        header('content-type:application/json; charset=UTF-8');
        
        print_r(json_encode($data));
        die();

        ob_end_flush();
    }
}