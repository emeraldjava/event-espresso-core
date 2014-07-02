<?php

if (!defined('EVENT_ESPRESSO_VERSION')) {
	exit('No direct script access allowed');
}

/**
 *
 * EE_Payment_Processor_Test
 *
 * @package			Event Espresso
 * @subpackage
 * @author				Mike Nelson
 *
 */
/**
 * @group payment_methods
 * @group agg
 */
class EE_Payment_Processor_Test extends EE_UnitTestCase{

	public function test_process_payment__onsite__success(){
		$pm = $this->new_model_obj_with_dependencies('Payment_Method', array('PMD_type' => 'Mock_Onsite' ) );
		$transaction = $this->_new_typical_transaction();
		$billing_form = $pm->type_obj()->billing_form();
		$billing_form->receive_form_submission( array(
			'status'=>  EEM_Payment::status_id_approved,
			'credit_card' => '4111 1111 1111 1111',
			'exp_month' => '12',
			'exp_year' => '2032',
			'cvv' => '123'
		));
		global $wp_actions;
		EE_Registry::instance()->load_helper( 'Array' );
		$successful_payment_actions = EEH_Array::is_set( $wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 );
		$payment = EE_Payment_Processor::instance()->process_payment( $pm, $transaction, NULL, $billing_form, 'success', 'CART', TRUE, TRUE );
		$this->assertInstanceOf( 'EE_Payment', $payment );
		$this->assertEquals( EEM_Payment::status_id_approved, $payment->status() );
		$this->assertEquals( $successful_payment_actions + 1, $wp_actions[ 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful' ] );
	}

	public function test_process_payment__onsite__declined(){
		$pm = $this->new_model_obj_with_dependencies('Payment_Method', array('PMD_type' => 'Mock_Onsite' ) );
		$transaction = $this->_new_typical_transaction();
		$billing_form = $pm->type_obj()->billing_form();
		$billing_form->receive_form_submission( array(
			'status'=>  EEM_Payment::status_id_declined,
			'credit_card' => '4111 1111 1111 1111',
			'exp_month' => '12',
			'exp_year' => '2032',
			'cvv' => '123'
		));
		global $wp_actions;
		EE_Registry::instance()->load_helper( 'Array' );
		$successful_payment_actions = EEH_Array::is_set( $wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 );
		$payment = EE_Payment_Processor::instance()->process_payment( $pm, $transaction, NULL, $billing_form, 'success', 'CART', TRUE, TRUE );
		$this->assertInstanceOf( 'EE_Payment', $payment );
		$this->assertEquals( EEM_Payment::status_id_declined, $payment->status() );
		$this->assertEquals( $successful_payment_actions, EEH_Array::is_set($wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 ) );
	}

	public function test_process_payment__offsite__declined_then_approved(){
		$pm = $this->new_model_obj_with_dependencies('Payment_Method', array('PMD_type' => 'Mock_Offsite' ) );
		$transaction = $this->_new_typical_transaction();

		global $wp_actions;
		EE_Registry::instance()->load_helper( 'Array' );
		$successful_payment_actions = EEH_Array::is_set( $wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 );
		$payment = EE_Payment_Processor::instance()->process_payment( $pm, $transaction, NULL, NULL, 'success', 'CART', TRUE, TRUE );
		$this->assertInstanceOf( 'EE_Payment', $payment );
		//assert that the paymetn still has its default status
		$this->assertEquals( EEM_Payment::instance()->field_settings_for( 'STS_ID' )->get_default_value(), $payment->status() );
		//assert that the we haven't notified of successful payment JUST yet...
		$this->assertEquals( $successful_payment_actions, EEH_Array::is_set($wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 ) );

		//DECLINED IPN
		$payment = EE_Payment_Processor::instance()->process_ipn( array('status' => EEM_Payment::status_id_pending, 'gateway_txn_id' =>$payment->txn_id_chq_nmbr() ), $transaction, $pm );
		//payment should be what the gatewya set it to be, whcih was failed
		$this->assertEquals( EEM_Payment::status_id_pending, $payment->status() );
		//and the payment-approved action should have NOT been triggered
		$this->assertEquals( $successful_payment_actions, EEH_Array::is_set($wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 ) );

		//SUCCESSFUL IPN
		$payment = EE_Payment_Processor::instance()->process_ipn( array('status' => EEM_Payment::status_id_approved, 'gateway_txn_id' =>$payment->txn_id_chq_nmbr() ), $transaction, $pm );
		//payment should be what the gatewya set it to be, whcih was failed
		$this->assertEquals( EEM_Payment::status_id_approved, $payment->status() );
		//and the payment-approved action should have been triggered
		$this->assertEquals( $successful_payment_actions + 1, EEH_Array::is_set($wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 ) );

		//DUPLICATE SUCCESS IPN
		//for this, we need to reset payment model so we fetch a NEW payment object, instead of reusing the old
		//and because the payment method caches a payment method type which caches a gateway which caches the paymetn model,
		//we also need to reset the payment method
		EEM_Payment::reset();
		$pm = EEM_Payment_Method::reset()->get_one_by_ID( $pm->ID() );
		$payment = EE_Payment_Processor::instance()->process_ipn( array('status' => EEM_Payment::status_id_approved, 'gateway_txn_id' =>$payment->txn_id_chq_nmbr() ), $transaction, $pm );
		//payment should be what the gatewya set it to be, whcih was failed
		$this->assertEquals( EEM_Payment::status_id_approved, $payment->status() );
		//and the payment-approved action should have NOT been triggered this time because it's a duplicate
		$this->assertEquals( $successful_payment_actions + 1, EEH_Array::is_set($wp_actions, 'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful', 0 ) );
	}

	public function setUp(){
		parent::setUp();
		$this->_pretend_addon_hook_time();
		EE_Register_Payment_Method::register('onsite', array(
			'payment_method_paths'=>array(
				EE_TESTS_DIR . 'mocks' . DS . 'payment_methods' . DS . 'Mock_Onsite'
			)
		));
		EE_Register_Payment_Method::register('offsite',array(
			'payment_method_paths' => array(
				EE_TESTS_DIR . 'mocks' . DS . 'payment_methods' . DS . 'Mock_Offsite'
			)
		));

	}
	public function tearDown(){
		EE_Register_Payment_Method::deregister( 'onsite' );
		EE_Register_Payment_Method::deregister( 'offsite' );
		parent::tearDown();
	}

	/**
	 * Creates a transaction with all valid data (ie, it's for an event that has
	 * datetimes and tickets etc)
	 * @return EE_Transaction
	 */
	protected function _new_typical_transaction(){
		$transaction = $this->new_model_obj_with_dependencies( 'Transaction', array( 'TXN_total'=>10.00 ) );
		$ticket = $this->new_model_obj_with_dependencies( 'Ticket' , array( 'TKT_price' => 10.0 ) );
		$user = $this->factory->user->create_and_get();
		$user->add_role('administrator');
		$e = $this->new_model_obj_with_dependencies('Event', array('EVT_wp_user'=>$user->ID ) );
		$r = $this->new_model_obj_with_dependencies( 'Registration', array(
			'TXN_ID'=>$transaction->ID(),
			'TKT_ID' => $ticket->ID(),
			'EVT_ID' => $e->ID(),
			'REG_final_price'=>10.00,
			'REG_count' => EEM_Registration::PRIMARY_REGISTRANT_COUNT));
		$e = $r->event();
		$dtt = $this->new_model_obj_with_dependencies( 'Datetime', array(
			'EVT_ID'=>$e->ID(),
			'DTT_EVT_start'=> current_time( 'timestamp' ) + 60 * 60,
			'DTT_EVT_end' => current_time( 'timestamp' ) + 5 * 60 * 60 ) );

		$dtt->_add_relation_to( $ticket, 'Ticket' );

		return $transaction;
	}
}

// End of file EE_Payment_Processor_Test.php