Yii компонент для работы с api сервиса проведения платежей [Robokassa](http://robokassa.ru/)

## Установка

Загрузите yii-robokassa из этого репозитория github:

    cd protected/components
    git clone https://github.com/ladamalina/yii-robokassa.git

В protected/config/main.php внесите следующие строки:

```php
'components' => [
    'robokassa' => [
        'class' => 'application.components.yii-robokassa.Robokassa',
        'sMerchantLogin' => 'login',
        'sMerchantPass1' => 'pass1',
        'sMerchantPass2' => 'pass2',
        'sCulture' => 'ru',
        'sIncCurrLabel' => '',
        'orderModel' => 'Invoice', // ваша модель для выставления счетов
        'priceField' => 'amount', // атрибут модели, где хранится сумма
        'isTest' => true, // тестовый либо боевой режим работы
    ]
]
```

## Использование

Контроллер:

```php
class PaymentController extends EController {

    /*
        Всё начинается здесь. Заводим в базе запись с новым выставленным счетом, 
        и передаем компоненту его ID, сумму, краткое описание и опционально 
        e-mail пользователя. Можно не выносить эти данные в отдельную модель, 
        а использовать атрибуты оформленного пользователем заказа 
        (для интернет-магазинов).
    */
    public function actionIndex() {
        // Выставляем счет
        $invoice = new Invoice;
        if (isset($_POST['Invoice'])) {
            $invoice->attributes = $_POST['Invoice'];
            $invoice->user_id = Yii::app()->user->id;
            $invoice->description = 'Внесение средств на личный счет.';
            if ($invoice->save()) {
                // Компонент переадресует пользователя в свой интерфейс оплаты
                Yii::app()->robokassa->pay(
                    $invoice->amount,
                    $invoice->id,
                    $invoice->description,
                    Yii::app()->user->profile->email
                );
            }
        }
    }

    /*
        К этому методу обращается робокасса после завершения интерактива 
        с пользователем. Это может произойти мгновенно либо в течение нескольких 
        минут. Здесь следует отметить счет как оплаченный либо обработать 
        отказ от оплаты.
    */
    public function actionResult() {
        $rc = Yii::app()->robokassa;

        // Коллбэк для события "оплата произведена"
        $rc->onSuccess = function($event){
            $transaction = Yii::app()->db->beginTransaction();
            // Отмечаем время оплаты счета
            $InvId = Yii::app()->request->getParam('InvId');
            $invoice = Invoice::model()->findByPk($InvId);
            $invoice->paid_at = new CDbExpression('NOW()');
            if (!$invoice->save()) {
                $transaction->rollback();
                throw new CException("Unable to mark Invoice #$InvId as paid.\n" 
                	. CJSON::encode($invoice->getErrors()));
            }
            $transaction->commit();
        };

        // Коллбэк для события "отказ от оплаты"
        $rc->onFail = function($event){
            // Например, удаляем счет из базы
            $InvId = Yii::app()->request->getParam('InvId');
            Invoice::model()->findByPk($InvId)->delete();
        };

        // Обработка ответа робокассы
        $rc->result();
    }

    /*
        Сюда из робокассы редиректится пользователь 
        в случае отказа от оплаты счета.
    */
    public function actionFailure() {
        Yii::app()->user->setFlash('global', 'Отказ от оплаты. Если вы столкнулись 
        	с трудностями при внесении средств на счет, свяжитесь 
        	с нашей технической поддержкой.');

        $this->redirect(['index']);
    }

    /*
        Сюда из робокассы редиректится пользователь в случае успешного проведения 
        платежа. Обратите внимание, что на этот момент робокасса возможно еще 
        не обратилась к методу actionResult() и нам неизвестно, поступили средства 
        на счет или нет.
    */
    public function actionSuccess() {
        $InvId = Yii::app()->request->getParam('InvId');
        $invoice = Invoice::model()->findByPk($InvId);
        if ($invoice) {
            if ($invoice->paid_at) {
                // Если робокасса уже сообщила ранее, что платеж успешно принят
                Yii::app()->user->setFlash('global', 
                	'Средства зачислены на ваш личный счет. Спасибо.');
            } else {
                // Если робокасса еще не отзвонилась
                Yii::app()->user->setFlash('global', 'Ваш платеж принят. Средства 
                	будут зачислены на ваш личный счет в течение нескольких минут. 
                	Спасибо.');
            }
        }

        $this->redirect(['index']);
    }

}
```

Модель Invoice:

```php
class Invoice extends CActiveRecord {

	public function tableName() {
		return '{{invoice}}';
	}

	public function rules() {
		return [
			['user_id, amount, description', 'required'],
			['description, created_at, paid_at', 'length', 'max'=>200],
		];
	}

	public function relations() {
		return [
			'user' => [self::BELONGS_TO, 'User', 'user_id'],
		];
	}
	
	public static function model($className=__CLASS__) {
		return parent::model($className);
	}
}
```
