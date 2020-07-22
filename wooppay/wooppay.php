<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2012-2015 Wooppay
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */


defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
if (!class_exists('WooppaySoapClient')) {
	require('WooppaySoapClient.php');
}

class plgVmPaymentWooppay extends vmPSPlugin
{

	const WOOPPAY_LOG_INFO = 0;
	const WOOPPAY_LOG_WARNING = 1;
	const WOOPPAY_LOG_ERROR = 2;

	/**
	 * @param $subject
	 * @param $config
	 */
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	protected function wplog($contents, $mode = self::WOOPPAY_LOG_INFO)
	{
		$translations = array(
			self::WOOPPAY_LOG_INFO => 'Info',
			self::WOOPPAY_LOG_WARNING => 'Warning',
			self::WOOPPAY_LOG_ERROR => 'ERROR'
		);

		$now = new DateTime();
		$contents = $now->format('Y-m-d H:i:s') . ' [' . $translations[$mode] . '] ' . $contents;
		error_log($contents . "\n", 3, JPATH_ROOT . DS . 'logs' . DS . 'com_virtuemart.payment_wooppay.log');
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 *
	 * @return
	 */
	public function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('Payment Wooppay Table');
	}

	/**
	 * Fields to create the payment table
	 *
	 * @return array
	 */
	function getTableSQLFields()
	{
		$SQLfields = array(
			'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name' => 'varchar(5000)',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency' => 'char(3)',
			'payment_order_total_kzt' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'invoice_url' => 'varchar(500)',
			'wooppay_operation_id' => 'int(1) UNSIGNED'
		);

		return $SQLfields;
	}


	/**
	 * Display stored payment data for an order
	 *
	 * @param $virtuemart_order_id
	 * @param $virtuemart_payment_id
	 *
	 * @return
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
			return NULL;
		}
		VmConfig::loadJLang('com_virtuemart');

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('VMPAYMENT_WOOPPAY_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('VMPAYMENT_WOOPPAY_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * @param VirtueMartCart $cart
	 * @param                $method
	 * @param array $cart_prices
	 *
	 * @return
	 */
	function getCosts(VirtueMartCart $cart, $method, $cart_prices)
	{
		if (preg_match('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}

		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 *
	 * @param $cart VirtueMartCart
	 * @param $method TablePaymentMethods
	 * @param $cart_prices
	 *
	 * @return boolean
	 */
	protected function checkConditions($cart, $method, $cart_prices)
	{
		//лимиты операции в тенге в Wooppay
		$min_amount = 5;
		$max_amount = 198200; //todo привязать к МРП
		$basic_currency_code_3 = 'KZT';

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		// set currency ID into $method->payment_currency
		$this->getPaymentCurrency($method);
		$this->getPaymentCurrency($method, true);

		$currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');

		// если валюта заказа не в тенге и в настройках метода запрещена конвертация, проверка падает
		if ($currency_code_3 != $basic_currency_code_3) {
			return false;
		}

		$amount = $cart_prices['salesPrice'];
		//проверяем, попадает ли сумма в лимиты операции
		if (!($amount >= $min_amount AND $amount <= $max_amount)) {
			return false;
		}

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			return true;
		}

		return false;
	}

	/*
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @param $jplugin_id
	 *
	 * @return
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/*
	 * plgVmonSelectedCalculatePricePayment
	 * Calculate the price (value, tax_id) of the selected method
	 * It is called by the calculator
	 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	 *
	 * @param VirtueMartCart $cart
	 * @param array          $cart_prices
	 * @param                $cart_prices_name
	 *
	 * @return
	 */

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 *
	 * @return
	 */
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;

	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @param VirtueMartCart $cart
	 * @param array $cart_prices
	 * @param                $paymentCounter
	 *
	 * @return
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param $virtuemart_order_id
	 * @param $virtuamart_paymentmethod_id
	 * @param $payment_name
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id method used for this order
	 *
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $data
	 *
	 * @return
	 */
	function plgVmDeclarePluginParamsPayment($name, $id, &$data)
	{
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $table
	 *
	 * @return
	 */
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}


	/**
	 * This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.
	 *
	 * @return
	 */
	function plgVmOnPaymentNotification()
	{
		$vm_status_confirmed = 'C';
		$vm_status_pending = 'P';

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		//После подтверждения оплаты Wooppay шлёт в цикле запрос на подтверждение заказа
		//Если вернуть такой ответ, Wooppay прекратит слать запрос
		$enough = json_encode(array('data' => 1));

		$this->wplog('Входящий запрос на подтверждение заказа от ' . $_SERVER['REMOTE_HOST'] . '(' . $_SERVER['REMOTE_ADDR'] . ') на URI ' . $_SERVER['REQUEST_URI']);
		$get = new JInput();
		$uri_secret = $get->Get('hash');
		$uri_order_number = $get->Get('ordernum');

		if (empty($uri_secret) || empty($uri_order_number)) {
			$this->wplog('Отсутствуют нужные параметры в запросе', self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($uri_order_number))) {
			$this->wplog('Заказ ' . $uri_order_number . ' не найден', self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}
		/**
		 * @var $modelOrder VirtueMartModelOrders
		 */
		$modelOrder = VmModel::getModel('orders');
		$order = $modelOrder->getOrder($virtuemart_order_id);

		if (!$order) {
			$this->wplog('Не удалось загрузить заказ с ID ' . $virtuemart_order_id, self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}

		$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
		if (!$method) {
			$this->wplog('Не удалось загрузить данные метода оплаты Wooppay', self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}
		$secret = hash('md5', $order['details']['BT']->order_number . ':' . $order['details']['BT']->order_pass . ':' . $method->pass);

		if ($uri_secret != $secret) {
			$this->wplog('Хэши для заказа ' . $uri_order_number . ' не совпадают', self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}

		if ($order['details']['BT']->order_status != $vm_status_pending) {
			$this->wplog('Попытка подтвердить заказ на неверном статусе. Заказ ' . $order['details']['BT']->order_number . ' на статусе ' . $order['details']['BT']->order_status, self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}

		$order_data = $this->_getInternalData($virtuemart_order_id);
		if (!$order_data || !$order_data->wooppay_operation_id) {
			$this->wplog('Не удалось загрузить данные Wooppay для заказа ' . $uri_order_number, self::WOOPPAY_LOG_ERROR);
			echo $enough;
			exit;
		}

		// Проверяем статус оплаты заказа
		try {
			$client = $this->_wooppayLogin($method);
			$operationdata_request = new CashGetOperationDataRequest();
			$operationdata_request->operationId = array($order_data->wooppay_operation_id);
			$operation_data = $client->cash_getOperationData($operationdata_request);
		} catch (Exception $e) {
			$this->wplog('Ошибка проверки статуса заказа: ' . $e->getMessage(), self::WOOPPAY_LOG_WARNING);
			return null;
		}

		if (!isset($operation_data->response->records['0']->status) || empty($operation_data->response->records['0']->status)) {
			$this->wplog('Не удалось получить статус оплаты заказа от Wooppay', self::WOOPPAY_LOG_WARNING);
			return null;
		}

		$operation = $operation_data->response->records['0'];

		if ($operation->status != WooppayOperationStatus::OPERATION_STATUS_DONE) {
			//позволим Wooppay слать запрос, пока не получим нужный статус
			return null;
		}

		//здесь мы уверены, что операция оплаты успешно прошла
		$order['order_status'] = $vm_status_confirmed;
		$order['customer_notified'] = 1;
		$order['comments'] = 'ID транзакции в Wooppay - ' . $order_data->wooppay_operation_id;
		$modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);
		$order['paymentName'] = $method->payment_name;

		$fresh_order = $modelOrder->getOrder($virtuemart_order_id);

		if ($fresh_order['details']['BT']->order_status == $vm_status_confirmed) {
			$this->wplog('Заказ ' . $uri_order_number . ' подтверждён', self::WOOPPAY_LOG_INFO);
			echo $enough;
			exit;
		} else {
			$this->wplog('Не получилось подтвердить заказ ' . $uri_order_number . '. Не сменился ли код статуса подтверждённого заказа?', self::WOOPPAY_LOG_WARNING);
			return null;
		}

	}

	/**
	 * @param $html
	 *
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived(&$html)
	{
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

		// the payment itself should send the parameter needed.
		$get = new JInput();
		$virtuemart_paymentmethod_id = $get->Get('pm', 0);
		$order_number = $get->Get('on', 0);

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		$payment_name = $this->renderPluginName($method);
		$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);

		return TRUE;
	}

	function _getPaymentResponseHtml($paymentTable, $payment_name)
	{
		VmConfig::loadJLang('com_virtuemart');

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('VMPAYMENT_WOOPPAY_PAYMENT_NAME', $payment_name);
		if (!empty($paymentTable)) {
			$html .= $this->getHtmlRow('VMPAYMENT_WOOPPAY_ORDER_NUMBER', $paymentTable->order_number);
		}
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * This shows the plugin for choosing in the payment list of the checkout process.
	 *
	 * @param VirtueMartCart $cart
	 * @param integer $selected
	 * @param                $htmlIn
	 *
	 * @return
	 */
	function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		$session = JFactory::getSession();
		$errors = $session->get('errorMessages', 0, 'vm');

		if ($errors != "") {
			$errors = unserialize($errors);
			$session->set('errorMessages', "", 'vm');
		} else {
			$errors = array();
		}

		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/** Вызывается после подтверждения заказа покупателем
	 * @param $cart VirtueMartCart
	 * @param $order
	 * @return
	 */
	function plgVmConfirmedOrder($cart, $order)
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$vendorId = 0;
		$html = "";

		VmConfig::loadJLang('com_virtuemart', true);
		VmConfig::loadJLang('com_virtuemart_orders', true);

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$this->getPaymentCurrency($method, true);
		$currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
		$basic_currency = 'KZT';
		if ($currency_code_3 != $basic_currency) {
			return $this->processConfirmedOrderPaymentResponse(0, $cart, $order, vmText::_("Эта валюта пока что не поддерживается Wooppay"), '');
		}
		$usrBT = $order['details']['BT'];
		/**
		 * @var $paymentCurrency CurrencyDisplay
		 */
		$total = round($usrBT->order_total, 2);

		$get = new JInput();

		$usr = array();
		$usr['phone'] = (!empty($usrBT->phone_1)) ? $usrBT->phone_1 : $usrBT->phone_2;
		$usr['email'] = $usrBT->email;
		$hash = hash('md5', $usrBT->order_number . ':' . $usrBT->order_pass . ':' . $method->pass);
		$reference_id = ($method->env == 'test') ? time() : $order['details']['BT']->virtuemart_order_id;

		// Коллекционируем данные для создания инвойса
		$invoice_request = new CashCreateInvoiceByServiceRequest();

		// ID заказа, если при использовании этого id заказа на тестовом сервере вы получите не то,
		// что ожидали (404, 403 или вообще другой заказ), то попробуйте другой id
		$invoice_request->referenceId = $method->order_prefix . '_' . $reference_id;

		// адрес, на который будет переадресован пользователь после оплаты
		$invoice_request->backUrl = JROUTE::_(
			JURI::root()
			. 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on='
			. $usrBT->order_number
			. '&pm='
			. $order['details']['BT']->virtuemart_paymentmethod_id
			. '&Itemid='
			. $get->get('Itemid'));

		// Запрос, который мы высылаем вам после успешной оплаты.
		// Для обеспечения безопасности рекомендуется использовать $key, который вы передаете нам, привязав его к конкретному заказу.
		// При успешном завершении скрипта, он должен возвращать JSON {"data":1}.
		// При любом другом ответе requestUrl будет вызываться в цикле.
		$invoice_request->requestUrl = JROUTE::_(
			JURI::root()
			. 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&ordernum='
			. $usrBT->order_number
			. '&hash='
			. $hash);

		// строка - что увидит клиент на странице оплаты инвойса. пример - "Оплата закза номер 100"
		$invoice_request->addInfo = 'Оплата заказа №' . $order['details']['BT']->order_number;

		// сумма заказа
		$invoice_request->amount = $total;

		// срок действия операции, можно оставить пустым
		$invoice_request->deathDate = ''; //date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' + 1 day'))

		// имя сервиса мерчанта
		$invoice_request->serviceName = $method->service_name;

		// системное описание операции, отображается в истории пользователя
		$invoice_request->description = '';
		$invoice_request->userEmail = $usr['email'];
		$invoice_request->userPhone = $usr['phone'];

		try {
			// Логинимся
			$client = $this->_wooppayLogin($method);
			// Создаём операцию инвойса
			$invoice_data = $client->cash_createInvoice($invoice_request);

		} catch (BadResponseException $e) {
			$this->wplog('Ошибка получения ответа при обращении к Wooppay: ' . $e->getMessage(), self::WOOPPAY_LOG_WARNING);
			return $this->processConfirmedOrderPaymentResponse(0, $cart, $order, vmText::_("Что-то пошло не так, попробуйте ещё раз"), '');
		} catch (Exception $e) {
			$this->wplog('Wooppay возвратил ошибку: ' . $e->getMessage(), self::WOOPPAY_LOG_ERROR);
			return $this->processConfirmedOrderPaymentResponse(0, $cart, $order, vmText::_("Невозможно создать инвойс для заказа, пожалуйста, свяжитесь с продавцом"), '');
		}
		$cart->emptyCart();
		$this->storePSPluginInternalData(array(
			'virtuemart_order_id' => $usrBT->virtuemart_order_id,
			'order_number' => $usrBT->order_number,
			'virtuemart_paymentmethod_id' => $usrBT->virtuemart_paymentmethod_id,
			'payment_name' => '<a href="' . $invoice_data->response->operationUrl . '">' . $method->payment_name . '</a>',
			'payment_order_total' => $usrBT->order_total,
			'payment_currency' => $currency_code_3,
			'payment_order_total_kzt' => $total,
			'invoice_url' => $invoice_data->response->operationUrl,
			'wooppay_operation_id' => $invoice_data->response->operationId
		));

		header('Location: ' . $invoice_data->response->operationUrl);
		exit;
	}


	/**
	 * @param $method
	 * @return WooppaySoapClient
	 * @throws WooppaySoapException
	 */
	function _wooppayLogin($method)
	{
		if ($method->env == 'prod') {
			$url = 'https://www.wooppay.com/api/wsdl';
		} else {
			$url = 'https://www.test.wooppay.com/api/wsdl';
		}

		$client = new WooppaySoapClient($url);

		if ($client->login($method->login, $method->pass)) {
			return $client;
		} else {
			return false;
		}
	}

	/**
	 * @param $virtuemart_order_id
	 * @param string $order_number
	 * @return mixed|string
	 */
	function _getInternalData($virtuemart_order_id, $order_number = '')
	{

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}

		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return null;
		}
		return $paymentTable;
	}

	/**
	 * @param $virtualmart_order_id
	 * @param $html
	 */
	function _handlePaymentCancel($virtuemart_order_id, $html)
	{
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$modelOrder = VmModel::getModel('orders');
		$modelOrder->remove(array('virtuemart_order_id' => $virtuemart_order_id));
		// error while processing the payment
		$mainframe = JFactory::getApplication();
		$mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment'), $html);
	}

	/**
	 * takes a string and returns an array of characters
	 *
	 * @param string $input string of characters
	 * @return array
	 */
	function toCharArray($input)
	{
		$len = strlen($input);
		for ($j = 0; $j < $len; $j++) {
			$char [$j] = substr($input, $j, 1);
		}
		return ($char);
	}

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}
		if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		if (empty($payments[0]->email_currency)) {
			$vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
			$db = JFactory::getDBO();
			$q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`=' . $vendorId;
			$db->setQuery($q);
			$emailCurrencyId = $db->loadResult();
		} else {
			$emailCurrencyId = $payments[0]->email_currency;
		}
	}
}

defined('_JEXEC') or die('Restricted access');

/*
 * This class is used by VirtueMart Payment  Plugins
 * which uses JParameter
 * So It should be an extension of JElement
 * Those plugins cannot be configured througth the Plugin Manager anyway.
 */
if (!class_exists('VmConfig')) {
	require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
}
if (!class_exists('ShopFunctions')) {
	require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'shopfunctions.php');
}

// Check to ensure this file is within the rest of the framework
defined('JPATH_BASE') or die();