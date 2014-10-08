<?php

class Robokassa extends CApplicationComponent
{
    public $sMerchantLogin;
    public $sMerchantPass1;
    public $sMerchantPass2;
    public $sCulture = 'ru';

    public $resultMethod = 'post';
    public $sIncCurrLabel = 'QiwiR';
    public $orderModel;
    public $priceField;
    public $isTest = false;

    public $params;

    protected $_order;

    public function pay($nOutSum, $nInvId, $sInvDesc, $sUserEmail)
    {
        $sign = $this->getPaySign($nOutSum, $nInvId, $sUserEmail);

        $url = $this->isTest
            ? 'http://test.robokassa.ru/Index.aspx?'
            : 'https://merchant.roboxchange.com/Index.aspx?';

        $url .= http_build_query(array(
            "MrchLogin" => $this->sMerchantLogin,
            "OutSum" => $nOutSum,
            "InvId" => $nInvId,
            "Desc" => $sInvDesc,
            "SignatureValue" => $sign,
            "IncCurrLabel" => $this->sIncCurrLabel,
            "Email" => $sUserEmail,
            "Culture" => $this->sCulture,
        ));

        Yii::app()->controller->redirect($url);
    }

    private function getPaySign($nOutSum, $nInvId)
    {
        $keys = array(
            $this->sMerchantLogin,
            $nOutSum,
            $nInvId,
            $this->sMerchantPass1,
        );
        return md5(implode(':', $keys));
    }

    public function result()
    {
        $var = $_GET + $_POST;
        extract($var);
        $event = new CEvent($this);

        $valid = true;

        if (!$valid || !isset($OutSum, $InvId, $SignatureValue)) {
            $this->params = array('reason' => 'Dont set need value');
            $valid = false;
        }

        if (!$valid || !$this->checkResultSignature($OutSum, $InvId, $SignatureValue)) {
            $this->params = array('reason' => 'Signature fail');
            $valid = false;
        }

        if (!$valid || !$this->isOrderExists($InvId)) {
            $this->params = array('reason' => 'Order not exists');
            $valid = false;
        }

        if (!$valid || $this->_order->{$this->priceField} != $OutSum) {
            $this->params = array('reason' => 'Order price error');
            $valid = false;
        }

        if ($valid) {
            if ($this->hasEventHandler('onSuccess')) {
                $this->params = array('order' => $this->_order);
                $this->onSuccess($event);
            }
        } else {
            if ($this->hasEventHandler('onFail')) {
                return $this->onFail($event);
            }
        }

        echo "OK{$InvId}\n";
    }

    private function isOrderExists($id)
    {
        $this->_order = CActiveRecord::model($this->orderModel)->findByPk((int)$id);

        if ($this->_order)
            return true;

        return false;
    }

    public function checkResultSignature($OutSum, $InvId, $SignatureValue, $checkType = 0)
    {
        $keys = array(
            $OutSum,
            $InvId,
            $checkType ? $this->sMerchantPass1 : $this->sMerchantPass2,
        );

        $sign = strtoupper(md5(implode(':', $keys)));

        if (strtoupper($SignatureValue) == $sign)
            return true;

        return false;
    }

    public function onSuccess($event)
    {
        $this->raiseEvent('onSuccess', $event);
    }

    public function onFail($event)
    {
        $this->raiseEvent('onFail', $event);
    }
}
