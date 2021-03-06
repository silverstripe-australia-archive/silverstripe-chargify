<?php
/**
 * Links each {@link Member} to a Chargify customer ID.
 *
 * @package silverstripe-chargify
 */
class ChargifyMemberExtension extends DataObjectDecorator {

	public function extraStatics() {
		return array('has_many' => array(
			'ChargifyCustomers'     => 'ChargifyCustomerLink',
			'ChargifySubscriptions' => 'ChargifySubscriptionLink'
		));
	}

	/**
	 * Links this member to the groups linked to a subscription product.
	 *
	 * @param object $subscription
	 */
	public function chargifySubscribe($subscription) {
		$groups = DataObject::get('Group', sprintf(
			'"ChargifyProductID" = %d', $subscription->product->id
		));

		if ($groups) foreach ($groups as $group) {
			if (!$this->owner->inGroup($group)) {
				$this->owner->Groups()->add($group, array(
					'Chargify'       => true,
					'SubscriptionID' => $subscription->id
				));
			}
		}

		$this->owner->extend('onAfterChargifySubscribe', $subscription);
	}

	/**
	 * Removes this member from any groups they have been added to by a chargify
	 * subscription.
	 *
	 * @param object $subscription
	 */
	public function chargifyUnsubscribe($subscription) {
		DB::query(sprintf(
			'DELETE FROM "Group_Members" WHERE "MemberID" = %d ' .
			'AND "Chargify" = 1 AND "SubscriptionID" = %d',
			$this->owner->ID, $subscription->id
		));

		$this->owner->extend('onAfterChargifyUnsubscribe', $subscription);
	}

	public function onBeforeWrite() {
		if (!count($this->owner->ChargifyCustomers())) return;

		$changed = array_keys($this->owner->getChangedFields());
		$push    = array('Email', 'FirstName', 'Surname');

		if (array_intersect($push, $changed)) {
			$connector = ChargifyService::instance()->getConnector();

			foreach ($this->owner->ChargifyCustomers() as $link) {
				try {
					$customer = $connector->getCustomerByID($link->CustomerID);
				} catch(ChargifyNotFoundException $e) {
					$link->delete();
					continue;
				}

				$customer->email      = $this->owner->Email;
				$customer->first_name = $this->owner->FirstName;
				$customer->last_name  = $this->owner->Surname;

				try {
					$connection->updateCustomer($customer);
				} catch(ChargifyValidationException $e) {  }
			}
		}
	}

	public function updateCMSFields($fields) {
		$fields->removeByName('ChargifyCustomers');
		$fields->removeByName('ChargifySubscriptions');

		$filter = sprintf('"MemberID" = %d', $this->owner->ID);
		$base   = ChargifyConfig::get_url();
		$link   = '<a href=\\"' . $base . '%1$s/$%2$s\\" target=\\"_blank\\">$%2$s</a>';

		$customers = new TableListField('ChargifyCustomers', 'ChargifyCustomerLink', array(
			'CustomerID' => 'Customer ID'
		), $filter);
		$customers->setFieldFormatting(array(
			'CustomerID' => sprintf($link, 'customers', 'CustomerID')
		));
		$customers = $customers->performReadonlyTransformation();

		$subscriptions = new TableListField('ChargifySubscriptions', 'ChargifySubscriptionLink', array(
			'SubscriptionID' => 'Subscription ID',
			'PageID'         => 'Subscription Page ID'
		), $filter);
		$subscriptions->setFieldFormatting(array(
			'SubscriptionID' => sprintf($link, 'subscriptions', 'SubscriptionID')
		));
		$subscriptions = $subscriptions->performReadonlyTransformation();

		$fields->addFieldsToTab('Root.Chargify', array(
			new HeaderField('LinkedCustomersHeader', 'Linked Customers'),
			$customers,
			new HeaderField('SubscriptionsHeader', 'Subscriptions'),
			$subscriptions
		));
	}

}