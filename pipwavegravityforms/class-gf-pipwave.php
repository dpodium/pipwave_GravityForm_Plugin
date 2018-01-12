<?php

add_action( 'wp', array( 'GFpipwave', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFpipwave extends GFPaymentAddOn {

    protected $_version                     = GF_PIPWAVE_VERSION;
    protected $_min_gravityforms_version    = '1.9';
    protected $_slug                        = 'pipwavegravityforms';
    protected $_path                        = 'pipwavegravityforms/pipwave.php';
    protected $_full_path                   = __FILE__;
    protected $_title                       = 'pipwave - Gravity Forms';
    protected $_short_title                 = 'pipwave';
	protected $_supports_callbacks          = true;

    private static $_instance               = null;

    public static function get_instance() {
        if( self::$_instance == null ) {
            self::$_instance = new GFpipwave();
        }
        return self::$_instance;
    }

//=PIPWAVE SCRIPT================================================================================================================================================

    public function setSignatureParam( $data ) {
        $signatureParam = array(
            'api_key'       => $data['api_key'],
            'api_secret'    => $data['api_secret'],
            'txn_id'        => $data['txn_id'],
            'amount'        => $data['amount'],
            'currency_code' => $data['currency_code'],
            'action'        => $data['action'],
            'timestamp'     => $data['timestamp'],
        );
        return $signatureParam;
    }

    //compare signature after receiving notification from pipwave
	public function compareSignature( $signature, $newSignature ) {
		if ( $signature != $newSignature ) {
			return $transaction_status = -1;
		}
		return;
	}

	//generate signature
	public function generate_pw_signature( $signatureParam ) {
		ksort( $signatureParam );
		$signature = "";
		foreach ( $signatureParam as $key => $value ) {
			$signature .= $key . ':' . $value;
		}
		return sha1( $signature );
	}

	//fire to pipwave
	public function send_request_to_pw( $data, $pw_api_key ) {
        $response = wp_remote_post( 'https://api.pipwave.com/payment', array(
                'method'      => 'POST',
                'timeout'     => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'x-api-key:' . $pw_api_key
                ),
                'body'        => json_encode( $data ),
                'cookies'     => array()
            )
        );
        $result = (isset($response['body'])?json_decode($response['body'], true ):null);
		return $result;
	}

	//render sdk THIS is the form that appear
	//NOT IN USED DUE TO GF cannot support
	public function renderSdk( $response, $api_key, $sdk_url, $loading_img ){
		if ($response['status'] == 200) {
			$api_data = json_encode([
				'api_key' => $api_key,
				'token' => $response['token']
			]);
			$result = <<<EOD
                    <div id="pwscript" class="text-center"></div>
                    <div id="pwloading" style="text-align: center;">
                        <img src="$loading_img" />
                    </div>
                    <script type="text/javascript">
                        var pwconfig = $api_data;
                        (function (_, p, w, s, d, k) {
                            var a = _.createElement("script");
                            a.setAttribute('src', w + d);
                            a.setAttribute('id', k);
                            setTimeout(function() {
                                var reqPwInit = (typeof reqPipwave != 'undefined');
                                if (reqPwInit) {
                                    reqPipwave.require(['pw'], function(pw) {
                                        pw.setOpt(pwconfig);
                                        pw.startLoad();
                                    });
                                } else {
                                    _.getElementById(k).parentNode.replaceChild(a, _.getElementById(k));
                                }
                            }, 800);
                        })(document, 'script', "$sdk_url", "pw.sdk.min.js", "pw.sdk.min.js", "pwscript");
                    </script>
EOD;
		} else {
			$result = isset($response['message']) ? (is_array($response['message']) ? implode('; ', $response['message']) : $response['message']) : "Error occured";
		}

		return $result;
	}

//=custom script===================================================================================================================================================

	//set data [prepare data needed to send to pipwave]
	public function setData( $entry, $settings, $feed ) {

		$country                = rgar( $entry, $feed['meta']['shippingInformation_country'] );
		$shippingCountryCode    = GF_Fields::get( 'address' )->get_country_code( $country );

		$country                = rgar( $entry, $feed['meta']['billingInformation_country'] );
		$billingCountryCode     = GF_Fields::get( 'address' )->get_country_code( $country );

		$string                 = rgar( $entry, $feed['meta']['fee_shipping_amount'] );
		$shipping_amount        = preg_replace('/[^0-9.]/', '', $string );

		//modify success url
		$pageURL                = $this->get_current_url();

		$urlInfo                = 'ids=' . urlencode( $feed['form_id'] ) . '|' . urlencode( rgar( $entry, 'id' ) );
		$urlInfo               .= '&hash=' . wp_hash( $urlInfo );
		$successUrl             = add_query_arg( 'gf_pipwave_return', base64_encode( $urlInfo ), $pageURL );

		$data = array(
			'action'            => 'initiate-payment',
			'timestamp'         => time(),
			'api_key'           => rgar( $settings, 'api_key' ),
			'api_secret'        => rgar( $settings, 'api_secret' ),
			'txn_id'            => rgar( $entry, 'id' ),
			'amount'            => (float)rgar( $entry, $feed['meta']['fee_payment_amount'] ),
			'currency_code'     => rgar( $entry, 'currency' ),
			'shipping_amount'   => (float)$shipping_amount,
			'session_info'      => array(
				'ip_address'    => rgar( $entry, 'ip' ),
			),
			'buyer_info' => array(
				//not sure about this id thing
				'id'            => rgar( $entry, $feed['meta']['billingInformation_email'] ),
				'email'         => rgar( $entry, $feed['meta']['billingInformation_email'] ),
				'first_name'    => rgar( $entry, $feed['meta']['billingInformation_firstName'] ),
				'last_name'     => rgar( $entry, $feed['meta']['billingInformation_lastName'] ),
				'contact_no'    => rgar( $entry, $feed['meta']['billingInformation_contactNumber'] ),
				'country_code'  => $billingCountryCode,
				'surcharge_group' => $feed['meta']['processing_fee_group'],
			),
			'shipping_info' => array(
				'name'          => rgar( $entry, $feed['meta']['shippingInformation_firstName'] ) . ' ' . rgar( $entry, $feed['meta']['shippingInformation_lastName'] ),
				'city'          => rgar( $entry, $feed['meta']['shippingInformation_city'] ),
				'zip'           => rgar( $entry, $feed['meta']['shippingInformation_zip'] ),
				'country_iso2'  => $shippingCountryCode,
				'email'         => rgar( $entry, $feed['meta']['shippingInformation_email'] ),
				'contact_no'    => rgar( $entry, $feed['meta']['shippingInformation_contactNumber'] ),
				'address1'      => rgar( $entry, $feed['meta']['shippingInformation_address'] ),
				'address2'      => rgar( $entry, $feed['meta']['shippingInformation_address2'] ),
				'state'         => rgar( $entry, $feed['meta']['shippingInformation_state'] ),
			),
			'billing_info' => array(
				'name'          => rgar( $entry, $feed['meta']['billingInformation_firstName'] ) . ' ' . rgar( $entry, $feed['meta']['billingInformation_lastName'] ),
				'city'          => rgar( $entry, $feed['meta']['billingInformation_city'] ),
				'zip'           => rgar( $entry, $feed['meta']['billingInformation_zip'] ),
				'country_iso2'  => $billingCountryCode,
				'email'         => rgar( $entry, $feed['meta']['billingInformation_email'] ),
				'contact_no'    => rgar( $entry, $feed['meta']['billingInformation_contactNumber'] ),
				'address1'      => rgar( $entry, $feed['meta']['billingInformation_address'] ),
				'address2'      => rgar( $entry, $feed['meta']['billingInformation_address2'] ),
				'state'         => rgar( $entry, $feed['meta']['billingInformation_state'] ),
			),
			'api_override' => array(
				'success_url'   => $successUrl, //! empty( $feed['meta']['successUrl'] ) ? urlencode( $feed['meta']['successUrl'] ) : $successUrl,
				'fail_url'      => !empty( $feed['meta']['failUrl'] ) ? urlencode( $feed['meta']['failUrl'] ) :  get_bloginfo( 'url' ) ,
				'notification_url' => urlencode( get_bloginfo( 'url' ) . '/?page=gf_pipwave_ipn' ),//'https://3d41d97e.ngrok.io/wordpress/?page=gf_pipwave_ipn',
			),
		);
		return $data;
	}

	//get current page url
	public function get_current_url() {
		$pageURL        = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port    = apply_filters( 'gform_paypal_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( $server_port != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}
		return $pageURL;
	}

	//get $someUrl['URL'],['RENDER_URL'],['LOADING_IMAGE_URL']
	public function setUrl( $testMode ) {
		$someUrl = $this->getUrlByTestMode( $testMode );
		return $someUrl;
	}

	//used in setUrl()
	public function getUrlByTestMode( $testMode ) {
		if ( $testMode == 0 ) {
			$someUrl = [
				'URL'               => 'https://api.pipwave.com/payment',
				'RENDER_URL'        => 'https://secure.pipwave.com/sdk/',
				'LOADING_IMAGE_URL' => 'https://secure.pipwave.com/images/loading.gif',
			];
		} else {
			if ( $testMode == 1 ) {
				$someUrl = [
					'URL'               => 'https://staging-api.pipwave.com/payment',
					'RENDER_URL'        => 'https://staging-checkout.pipwave.com/sdk/',
					'LOADING_IMAGE_URL' => 'https://staging-checkout.pipwave.com/images/loading.gif',
				];
			} else {
				$someUrl = '';//error
			}
		}
		return $someUrl;
	}

//=redirect to pipwave payment button===============================================================================================================================

    //this will run when the submit button is clicked
    public function redirect_url( $feed, $submission_data, $form, $entry ) {

	    if ( ! rgempty( 'gf_pipwave_return', $_GET ) ) {
		    return false;
	    }

	    //change payment status to 'processing'
        //GFAPI::update_entry_property( $entry['id'], 'payment_status', 'PendingPayment' );

	    $settings           = $this->get_plugin_settings();

	    $data               = $this->setData( $entry, $settings, $feed );

	    $signatureParam     = $this->setSignatureParam( $data );
	    $pwSignature        = $this->generate_pw_signature( $signatureParam );

	    //after put in pipwave signature, the data is now complete
	    $data['signature']  = $pwSignature;


	    $response           = $this->send_request_to_pw( $data, $data['api_key'] );
        if(isset($response['redirect_url'])) {
            $url = $response['redirect_url'];
        } else if(isset($response['token'])) {
            $url = 'https://checkout.pipwave.com/pay?token=';
            $url .= $response['token'];
        }
	    return $url;
    }


//=receive notification/IPN from pipwave============================================================================================================================

    //receive notification from pipwave [transaction status and data]
	public function callback() {
		header( 'HTTP/1.1 200 OK' );
		echo "OK";
		//IPN from pipwave
		$post_data = json_decode( file_get_contents( 'php://input' ), true );

		$timestamp          = ( isset( $post_data['timestamp'] ) && !empty( $post_data['timestamp'] ) ) ? $post_data['timestamp'] : time();
		$pw_id              = ( isset( $post_data['pw_id'] ) && !empty( $post_data['pw_id'] ) ) ? $post_data['pw_id'] : '';
		$order_number       = ( isset( $post_data['txn_id'] ) && !empty( $post_data['txn_id'] ) ) ? $post_data['txn_id'] : '';
		$pg_txn_id          = ( isset( $post_data['pg_txn_id'] ) && !empty( $post_data['pg_txn_id'] ) ) ? $post_data['pg_txn_id'] : '';
		$amount             = ( isset( $post_data['amount'] ) && !empty( $post_data['amount'] ) ) ? $post_data['amount'] : '';
		$currency_code      = ( isset( $post_data['currency_code'] ) && !empty( $post_data['currency_code'] ) ) ? $post_data['currency_code'] : '';
		$transaction_status = ( isset( $post_data['transaction_status'] ) && !empty( $post_data['transaction_status'] ) ) ? $post_data['transaction_status'] : '';
		$payment_method     = ( isset( $post_data['payment_method_title'] ) && !empty( $post_data['payment_method_title'] ) ) ? __('pipwave') . " - " . $post_data['payment_method_title'] : '';
		$signature          = ( isset( $post_data['signature'] ) && !empty( $post_data['signature'] ) ) ? $post_data['signature'] : '';
		$txn_sub_status     = ( isset( $post_data['txn_sub_status'] ) && !empty( $post_data['txn_sub_status'] ) ) ? $post_data['txn_sub_status'] : time();
		$total_amount       = ( isset( $post_data['total_amount'] ) && !empty( $post_data['total_amount'] ) ) ? $post_data['total_amount'] : 0.00;
		$final_amount       = ( isset( $post_data['final_amount'] ) && !empty( $post_data['final_amount'] ) ) ? $post_data['final_amount'] : 0.00;
		$refund             = $total_amount - $final_amount;
		$reverse_txn_id     = ( isset( $post_data['reverse_txn_id'] ) && !empty( $post_data['reverse_txn_id'] ) ) ? $post_data['reverse_txn_id'] : '';

		// pipwave risk execution result
		$pipwave_score      = isset( $post_data['pipwave_score'] ) ? $post_data['pipwave_score'] : '';
		$rule_action        = isset( $post_data['rules_action'] ) ? $post_data['rules_action'] : '';
		$message            = isset( $post_data['message'] ) ? $post_data['message'] : '';

		$settings           = $this->get_plugin_settings();

		$data_for_signature = array(
			'timestamp'         => $timestamp,
			'api_key'           => rgar( $settings, 'api_key' ),
			'pw_id'             => $pw_id,
			'txn_id'            => $order_number,
			'amount'            => $amount,
			'currency_code'     => $currency_code,
			'transaction_status'=> $transaction_status,
			'api_secret'        => rgar( $settings, 'api_secret' ),
		);

		$newSignature           = $this->generate_pw_signature( $data_for_signature );
		if ( $this->compareSignature( $signature, $newSignature ) ) {
			$transaction_status = $this->compareSignature( $signature, $newSignature );
		}

		$entry                  = GFAPI::get_entry( $order_number );
		$payment_method         = preg_replace("/[^a-zA-Z]+/", "", $payment_method);
		GFAPI::update_entry_property( $entry['id'], 'payment_method', $payment_method );
		GFAPI::update_entry_property( $entry['id'], 'transaction_id', $pg_txn_id );
		if ( $entry['payment_amount'] == '' ) {
			GFAPI::update_entry_property( $entry['id'], 'payment_amount', $final_amount );
		}
		//$transaction_status = -1;
		//$txn_sub_status = 502;
		$action = $this->processNotification( $transaction_status, $entry, $txn_sub_status, $final_amount, $refund, $pg_txn_id, $reverse_txn_id );

		if ( $transaction_status != 5 ) {
			if ( $pipwave_score != '' ) {
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'pipwave score: %s', 'pipwavegravityforms' ), $pipwave_score ) );
			}
			if ( $rule_action != '' && $rule_action !== 'credit_insufficient' ) {
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'rule action: %s', 'pipwavegravityforms' ), $rule_action ) );
			}
			if ( $message != '' ) {
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'message: %s', 'pipwavegravityforms' ), $message ) );
			}
		}

		return $action;
	}

	//to check whether is pipwave or other payment gateway
	// called by callback()
	public function is_callback_valid() {
		if ( rgget( 'page' ) != 'gf_pipwave_ipn' ) {
			return false;
		}
		return true;
	}

	//sub function
	//to get transaction status and change the payment status on gravity form
	/*
	 * @used-by callback()
	 * @uses    GFAPI::update_entry_property()      -- to update payment status
	 * @uses    GFPaymentAddOn::add_note()          -- to write note
	 */
	public function processNotification( $transaction_status, $entry, $txn_sub_status, $final_amount, $refund, $pg_txn_id, $reverse_txn_id )
	{
		$action[] = '';
		switch ( $transaction_status ) {
			case 5: // pending
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'PendingMerchant' );
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Merchant action pending.', 'pipwavegravityforms' ), $entry['id'] ) );
				break;
			case 1: // failed
				$action['id']             = $entry['id'] . '_Fail';
				$action['type']           = 'fail_payment';
				if ( isset( $pg_txn_id ) ) {
					$action['transaction_id'] = $pg_txn_id;
				}
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $final_amount;

				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Fail' );
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Payment failed.', 'pipwavegravityforms' ), $entry['id'] ) );
				break;
			case 2: // cancelled
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Cancelled' );
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Payment cancelled.', 'pipwavegravityforms' ), $entry['id'] ) );
				break;
			case 10: // complete
				//$status = SELF::PIPWAVE_PAID;
				//$order->setState($status)->setStatus($status);

				//502
				if ( $txn_sub_status == 502 ) {
					$action['id']               = $entry['id'] . '_Paid';
					$action['type']             = 'complete_payment';
					$action['transaction_id']   = $pg_txn_id;
					$action['amount']           = $final_amount;
					$action['entry_id']         = $entry['id'];
					$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
					$action['payment_method']	= 'pipwave';
					$action['ready_to_fulfill'] = !$entry['is_fulfilled'] ? true : false;
					GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Paid' );

					//GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Payment received: %s ', 'pipwavegravityforms' ), $entry['id'], $final_amount ) );
					//GFPaymentAddOn::insert_transaction( $entry['id'], 'payment', $action['transaction_id'], $action['amount'] );
				}

				break;
			case 20: // refunded
				$action['id']             = $entry['id'] . '_Refunded';
				$action['type']           = 'refund_payment';
				$action['transaction_id'] = $reverse_txn_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $entry['payment_amount'];

				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Refunded' );
				//GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s fully refunded. Refunded amount: %s', 'pipwavegravityforms' ), $entry['id'], $action['amount'] ) );
				//GFPaymentAddOn::insert_transaction( $entry['id'], 'refund', $action['transaction_id'], $action['amount'] );

				break;
			case 25: // partial refunded
				$action['id']             = $entry['id'] . '_PartialRefunded';
				$action['type']           = 'refund_payment';
				$action['transaction_id'] = $reverse_txn_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $refund;

				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'PartialRefunded' );
				//GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s partially refunded. Refunded amount: %s', 'pipwavegravityforms' ), $entry['id'], $action['amount'] ) );
				//GFPaymentAddOn::insert_transaction( $entry['id'], 'refund', $action['transaction_id'], $action['amount'] );

				break;
			case -1: // signature mismatch
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Signature mismatch.', 'pipwavegravityforms' ), $entry['id'] ) );
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Fail' );

				break;
			default:
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'UnknownError' );
				GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'ENTRY %s. Unknown error occurred.', 'pipwavegravityforms' ), $entry['id'] ) );
		}

		GFAPI::update_entry_property( $entry['id'], 'payment_amount', $final_amount );
		return $action;
	}

//=========================================================================================================================================================

	//this should be the payment success page
	public static function maybe_thankyou_page() {
		$instance = self::get_instance();
		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}
		if ( $str = rgget( 'gf_pipwave_return' ) ) {
			$str = base64_decode( $str );
			parse_str( $str, $query );

			if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] ) {

				list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

				$form = GFAPI::get_form( $form_id );
				$lead = GFAPI::get_entry( $lead_id );

				if ( ! class_exists( 'GFFormDisplay' ) ) {
					require_once( GFCommon::get_base_path() . '/form_display.php' );
				}
				$confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );

				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					header( "Location: {$confirmation['redirect']}" );
					exit;
				}
				GFFormDisplay::$submission[ $form_id ] = array(
																'is_confirmation'      => true,
				                                                'confirmation_message' => $confirmation,
				                                                'form'                 => $form,
				                                                'lead'                 => $lead
														 );
				return false;
			}
		}
	}

    //-SETTING PAGE-------GFdemo>dashboard>form>left panel>settings>pipwave-------------------------------------------
    
    public function plugin_settings_fields() {
        return array( 
            //first section
            array( 
                'title'         => esc_html__( 'pipwave', 'translator' ),
                'fields'        => array( 
                    //first row
                    array( 
                        'name'              => 'api_key',
                        'label'             => esc_html__( 'API Key', 'translator' ),
                        'type'              => 'text',
                        'class'             => 'medium',
                        'required'          => true,
                        'tooltip'           => '<h6>' . esc_html__( 'API Key', 'translator' ) . '</h6>' . sprintf( esc_html__( 'API Key provided by pipwave is in this %slink%s.', 'translator' ), '<a href="https://merchant.pipwave.com/development-setting/index" target="_blank">', '</a>' ),
                    ),
                    //second row
                    array( 
                        'name'              => 'api_secret',
                        'label'             => esc_html__( 'API Secret', 'translator' ),
                        //type = password is not implemented
                        'type'              => 'text',
                        'class'             => 'medium',
                        'required'          => true,
                        'tooltip'           => '<h6>' . esc_html__( 'API Secret', 'translator' ) . '</h6>' . sprintf( esc_html__( 'API Secret provided by pipwave is in this %slink%s.', 'translator' ), '<a href="https://merchant.pipwave.com/development-setting/index" target="_blank">', '</a>' ),
                    ),
                    //third row
	                /*
                    array(
                        'name'              => 'test_mode',
                        'label'             => esc_html__( 'Test Mode', 'translator' ),
                        'type'              => 'radio',
                        'default_value'     => '0',
                        'choices'           => array(
                            array(
                                'label'     => esc_html__( 'Yes', 'translator' ),
                                'value'     => '1',
                            ),
                            array(
                                'label'     => esc_html__( 'No', 'translator' ),
                                'value'     => '0',
                                'selected'  => true,
                            ),
                        ),
                        'horizontal'        => true,
                    ),
	                */
                    //save
                    array( 
                        'type'              => 'save',
                        'message'           => array( 'success' => esc_html__( 'Settings have been updated.', 'translator' ) ),
                    ),
                ),
            ),
        );
    }
    
    
    //-FORM SETTING PAGE----------GFDemo>Form>Choose a form>Edit>Setting>pipwave--------------------------------------

    public function feed_settings_fields() {
        $default_settings = parent::feed_settings_fields();

        //make test mode field, put in top section
        $fields = array(
            array( 
                'name'              => 'processing_fee_group',
                'label'             => esc_html__( 'Processing Fee Group', 'translator' ),
                'type'              => 'text',
                'class'             => 'medium',
                'tooltip'           => '<h6>' . esc_html__( 'Processing Fee Group', 'translator' ) . '</h6>' . sprintf( esc_html__( 'Payment Processing Fee Group can be configured %shere%s. Please fill referenceId in the blank.( if available )', 'translator' ), '<a href="https://merchant.pipwave.com/account/set-processing-fee-group#general-processing-fee-group" target="_blank">', '</a>' ),
            ),
	        /*
	        array(
		        'name'              => 'successUrl',
		        'label'             => 'Success Url',
		        'type'              => 'text',
		        'class'             => 'medium',
		        'tooltip'           => '<h6>Success Url</h6>pipwave will redirect to this page if payment success.',
	        ),
	        */
	        array(
		        'name'              => 'failUrl',
		        'label'             => 'Fail Url',
		        'type'              => 'text',
		        'class'             => 'medium',
		        'tooltip'           => '<h6>Fail Url</h6>pipwave will redirect to this page if payment fail.',
	        ),
        );

        //var_dump($default_settings);
        $default_settings   = parent::add_field_after( 'feedName', $fields, $default_settings );

        //overwrite default - because we dont have subscription functionality
		$fields = array(
			array(
				'name'     => 'transactionType',
				'label'    => esc_html__( 'Transaction Type', 'gravityforms' ),
				'type'     => 'select',
				'onchange' => "jQuery(this).parents('form').submit();",
				'choices'  => array(
					array(
						'label' => esc_html__( 'Select a transaction type', 'gravityforms' ),
						'value' => '',
					),
					array(
						'label' => esc_html__( 'Products and Services', 'gravityforms' ),
						'value' => 'product',
					),
					//array( 'label' => esc_html__( 'Subscription', 'gravityforms' ), 'value' => 'subscription' ),
				),
				'tooltip'  => '<h6>' . esc_html__( 'Transaction Type', 'gravityforms' ) . '</h6>' . esc_html__( 'Select a transaction type.', 'gravityforms' ),
			),
		);
	    $default_settings   = parent::replace_field( 'transactionType', $fields, $default_settings );

//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	    //get set shipping amount
	    $fee                = $this->feed_shipping_amount();

	    //put shipping ammount before billing information
	    $default_settings   = parent::add_field_before( 'billingInformation', $fee, $default_settings );

//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	    //get set product - DELETED because number of variation of product undefined
	    //$product = $this->feed_product();

	    //put shipping ammount before billing information
	    //$default_settings = parent::add_field_before( 'billingInformation', $product, $default_settings );

//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

		//create shipping information sub from copy and paste billing address
	    $shipping_info              = parent::get_field( 'billingInformation', $default_settings );

	    //change the name, label, tooltip
	    $shipping_info['name']      = 'shippingInformation';
	    $shipping_info['label']     = 'Shipping Information';
	    $shipping_info['tooltip']   = '<h6>Shipping Information</h6>Map your Form Fields to the available listed fields.';

	    //add customer first name / last name
	    $shipping_fields            = $shipping_info['field_map'];
	    $add_first_name             = true;
	    $add_last_name              = true;
	    $add_contact_no             = true;
	    foreach ( $shipping_fields as $mapping ) {
		    //check first/last name if it exist in billing fields
		    if ( $mapping['name'] == 'firstName' ) {
			    $add_first_name = false;
		    } else if ( $mapping['name'] == 'lastName' ) {
			    $add_last_name = false;
		    } else if ( $mapping['name'] == 'contactNumber' ) {
			    $add_contact_no = false;
		    }
	    }

	    if ( $add_contact_no ) {
		    array_unshift( $shipping_info['field_map'], array( 'name' => 'contactNumber', 'label' => esc_html__( 'Contact Number', 'translator' ), 'required' => false ) );
	    }
	    if ( $add_last_name ) {
		    //add last name
		    array_unshift( $shipping_info['field_map'], array( 'name' => 'lastName', 'label' => esc_html__( 'Last Name', 'translator' ), 'required' => false ) );
	    }
	    if ( $add_first_name ) {
		    array_unshift( $shipping_info['field_map'], array( 'name' => 'firstName', 'label' => esc_html__( 'First Name', 'translator' ), 'required' => false ) );
	    }

	    //place shipping information after billing information
	    $default_settings           = parent::add_field_after( 'billingInformation', $shipping_info, $default_settings );

//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	    // get biling info section
	    $billing_info               = parent::get_field( 'shippingInformation', $default_settings );

	    $billing_info['name']       = 'billingInformation';
	    $billing_info['label']      = 'Billing Information';
	    $billing_info['tooltip']    = '<h6>Billing Information</h6>Map your Form Fields to the available listed fields.';

	    //coz buyer.id and buyer.email need this
	    $billing_info['field_map'][3]['required'] = true;
	    //coz buyer.country need this
	    $billing_info['field_map'][9]['required'] = true;

	    $default_settings = parent::replace_field( 'billingInformation', $billing_info, $default_settings );

//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	    $default_settings = parent::remove_field( 'options', $default_settings );
        return $default_settings;
    }

    //@used in feed_settings_fields() to map shipping amount
    public function feed_shipping_amount() {
	    $test[0] = array(
		    'name'      => 'payment_amount',
		    'label'     => 'Payment Amount',
		    'required'  => true,
		    'tooltip'   => '<h6>Payment Amount</h6>Map this to the final amount.',
	    );
	    $test[1] = array(
		    'name'      => 'shipping_amount',
		    'label'     => 'Shipping Amount',
		    'required'  => false,
	    );
	    $fee = array(
		    'name'      => 'fee',
		    'label'     => 'Fee',
		    'type'      => 'field_map',
		    'field_map' => $test,
		    'tooltip'   => '<h6>Shipping Amount</h6>Map your Form Fields to the available listed fields.',
	    );
	    return $fee;
    }
	//@used in feed_settings_fields() to map product     CURRENLTY UNUSED
	public function feed_product() {
		$test[0] = array(
			'name'      => 'product_name',
			'label'     => 'Product Name',
			'required'  => false,
			'tooltip'   => '<h6>Product</h6>Map this to your product.',
		);
		$product = array(
			'name'      => 'product',
			'label'     => 'Product',
			'type'      => 'field_map',
			'field_map' => $test,
		);
		return $product;
	}

	/*
	 * @check plugin_setting_fields()
	 *
	 * if merchant did not configure API KEY/SECRET
	 * merchant not allowed to configure feed
	 */
	public function feed_list_no_item_message() {
		$settings = $this->get_plugin_settings();
		if ( ( rgar( $settings, 'api_key' ) == null || rgar( $settings, 'api_key' ) == '' ) || ( rgar( $settings, 'api_secret' ) == null || rgar( $settings, 'api_secret' ) == '' ) ) {
			return sprintf( esc_html__( 'To get started, please configure your %spipwave Settings%s!', 'translator' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
		} else {
			return parent::feed_list_no_item_message();
		}
	}

	//not sure what this function do
	public function save_feed_settings( $feed_id, $form_id, $settings ){
		return parent::save_feed_settings( $feed_id, $form_id, $settings );
	}


//=our own custom pipwave page==================================================================================================================================
	public function plugin_page(){
		$logo = plugins_url('images/logo_bnw.png', __FILE__);
    	$html = <<<EOD
<style>
    .center {
	    text-align: center;
	}
</style>
	<div class="center"><img src="$logo"/></div>
	<p class="center">Simple, reliable, and cost-effective way to accept payments online. And it's free to use!</p>
    <h1>Install & Configure pipwave in Gravity Forms</h1>
    <p>You will need a pipwave account. If you don't have one, don't worry, you can create one during the configuration process. Please click link below :</p>
		<ol>
			<li><a href="#install">Configure</a></li>
			<li><a href="#multiple">Multiple Payment Methods</a></li>
		</ol>
	<h2>Getting Started</h2>
	<h4 id="install">Configure</h4>
EOD;
        echo $html;

        $message1 = [
			'',
			'Click Dashboard. Then hover to Plugins. Then Click Installed Plugins',
			'Find our plugin (pipwave). Then Click Activate',
			'Click Settings',
			'Key in Api key and secret. Both of them can be obtained in the "question" figure',
			'Click Form',
			'Select your form, Click Setting, then Click pipwave',
			'Click pipwave',
			'Click Add New, then enter the information required. \'*\' firgure means the information is mandatory, and a field have to be create to map to it.',
		];
		for ( $i = 1; $i < 9; $i++ ) {
			$img    = plugins_url('/images/configure/configure' . $i . '.png', __FILE__);
			$html   = '<p>Step ' . $i . ' ' . $message1[$i] . '</p>';
			$html  .= '<img src = ' . $img . ' width="1000" ></img>';
			echo $html;
		}

		echo '<h3 id="multiple">Multiple Payment Methods</h3>';
		$message2 = [
			'',
			'Drag radio buttons from <b>standard field</b> into your form',//1
			'Click on the radio buttons form. Then enter the payment method name you configured. 
				<ul style="list-style: none;">
					<li>*you may set any name or non-existing names</li>
					<li>*buyer will see this and select their prefered payment method</li>
				</ul>
			',//2
			'The top rounded square is what buyer will see',
			'Remember to add title/label for this field',
			'Click Update and wait until you see the \'Form updated successfully\'',
			'Hover mouse to Settings and click pipwave.<br>Or you can click Settings, then select pipwave',
			'Click Edit. If there is no pipwave feeds available. Click Add New and follow instructions in <a href="#install">Install & Configure</a>',
			'Scroll down... (if you had configured this setting beforehand)',
			'Check Enable Condition',
			'Configure as in the figure',
			'Now it\'s done!',
		];
		for ( $i = 1; $i < 12; $i++ ) {
			$img    = plugins_url('/images/multiple_payment/multiple_payment_' . $i . '.png', __FILE__);
			$html   = '<p>Step ' . $i . ' ' . $message2[$i] . '</p>';
			$html  .= '<img src = ' . $img . ' width="1000" ></img>';
			echo $html;
		}
	}
}