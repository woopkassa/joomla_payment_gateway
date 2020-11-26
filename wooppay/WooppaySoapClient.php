<?php

class WooppaySoapClient
{

	private $c;

	public function __construct($url, $options = array())
	{
		try {
			$this->c = new SoapClient($url, $options);
		} catch (Exception $e) {
			throw new WooppaySoapException($e->getMessage());
		}
		if (empty($this->c)) {
			throw new WooppaySoapException('Cannot create instance of Soap client');
		}
	}

	/**
	 * @param $method
	 * @param $data
	 * @return WooppaySoapResponse
	 * @throws BadCredentialsException
	 * @throws UnsuccessfulResponseException
	 * @throws WooppaySoapException
	 */
	public function __call($method, $data)
	{
		try {
			$response = $this->c->$method($data[0]);
		} catch (Exception $e) {
			throw new WooppaySoapException($e->getMessage());
		}
		$response = new WooppaySoapResponse($response);
		switch ($response->error_code) {
			case 0:
				return $response;
				break;
			case 5:
				throw new BadCredentialsException();
				break;
			default:
				throw new UnsuccessfulResponseException('Error code ' . $response->error_code, $response->error_code);
		}

	}

	public function login($login, $pass)
	{
		$login_request = new CoreLoginRequest();
		$login_request->username = $login;
		$login_request->password = $pass;
		$response = $this->core_login($login_request);

		if (isset($response->response->session)) {
			$this->c->__setCookie('session', $response->response->session);
			return true;
		} else {
			return false;
		}
	}


	public function GetOperationData(CashGetOperationDataRequest $data)
	{
		return $this->cash_getOperationData($data);
	}

	public function cash_createInvoice(CashCreateInvoiceByServiceRequest $data)
	{
		return $this->cash_createInvoiceByService($data);
	}

	public function getLastDialog()
	{
		return array('req' => $this->c->__getLastRequest(), 'res' => $this->c->__getLastResponse());
	}
}

class CoreLoginRequest
{
	/**
	 * @var string $username
	 * @soap
	 */
	public $username;
	/**
	 * @var string $password
	 * @soap
	 */
	public $password;
	/**
	 * @var string $captcha
	 * @soap
	 */
	public $captcha = null;
}

class CashGetOperationDataRequest
{
	/**
	 * @var $operationId array
	 */
	public $operationId;

}

class CashCreateInvoiceRequest
{
	/**
	 * @var string $referenceId
	 * @soap
	 */
	public $referenceId;
	/**
	 * @var string $backUrl
	 * @soap
	 */
	public $backUrl;
	/**
	 * @var string $requestUrl
	 * @soap
	 */
	public $requestUrl = '';
	/**
	 * @var string $addInfo
	 * @soap
	 */
	public $addInfo;
	/**
	 * @var float $amount
	 * @soap
	 */
	public $amount;
	/**
	 * @var string $deathDate
	 * @soap
	 */
	public $deathDate;
	/**
	 * @var int $serviceType
	 * @soap
	 */
	public $serviceType = 4;
	/**
	 * @var string $description
	 * @soap
	 */
	public $description = '';
	/**
	 * @var int $orderNumber
	 * @soap
	 */
	public $orderNumber = null;
	/**
	 * @var string $userEmail
	 * @soap
	 */
	public $userEmail = null;
	/**
	 * @var string $userPhone
	 * @soap
	 */
	public $userPhone = null;
}

class CashCreateInvoiceExtendedRequest extends CashCreateInvoiceRequest
{
	/**
	 * @var string $userEmail
	 * @soap
	 */
	public $userEmail = '';
	/**
	 * @var string $userPhone
	 * @soap
	 */
	public $userPhone = '';
}

class CashCreateInvoiceExtended2Request extends CashCreateInvoiceExtendedRequest
{
	/**
	 * @var int $cardForbidden
	 * @soap
	 */
	public $cardForbidden;
}

class CashCreateInvoiceByServiceRequest extends CashCreateInvoiceExtended2Request
{
	/**
	 * @var string $serviceName
	 * @soap
	 */
	public $serviceName;
}

class WooppaySoapResponse
{

	public $error_code;
	public $response;

	public function __construct($response)
	{

		if (!is_object($response)) {
			throw new BadResponseException('Response is not an object');
		}

		if (!isset($response->error_code)) {
			throw new BadResponseException('Response do not contains error code');
		}
		$this->error_code = $response->error_code;

		if (!property_exists($response, 'response')) {
			throw new BadResponseException('Response do not contains response body');
		}
		$this->response = $response->response;
	}
}

class WooppayOperationStatus
{
	/**
	 * Новая
	 */
	const OPERATION_STATUS_NEW = 1;
	/**
	 * На рассмотрении
	 */
	const OPERATION_STATUS_CONSIDER = 2;
	/**
	 * Отклонена
	 */
	const OPERATION_STATUS_REJECTED = 3;
	/**
	 * Проведена
	 */
	const OPERATION_STATUS_DONE = 4;
	/**
	 * Сторнирована
	 */
	const OPERATION_STATUS_CANCELED = 5;
	/**
	 * Сторнирующая
	 */
	const OPERATION_STATUS_CANCELING = 6;
	/**
	 * Удалена
	 */
	const OPERATION_STATUS_DELETED = 7;
	/**
	 * На квитовании
	 */
	const OPERATION_STATUS_KVITOVANIE = 4;
	/**
	 * На ожидании подверждения или отказа мерчанта
	 */
	const OPERATION_STATUS_WAITING = 9;
}

class WooppaySoapException extends Exception
{
}

class BadResponseException extends WooppaySoapException
{
}

class UnsuccessfulResponseException extends WooppaySoapException
{
}

class BadCredentialsException extends UnsuccessfulResponseException
{
}
