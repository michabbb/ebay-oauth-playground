<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use NeilCrookes\OAuth2\Client\Provider\Ebay;
use NeilCrookes\OAuth2\Client\Token\EbayAccessToken;

class ebayOauthLogin extends Controller {

	private $EbayOauthProvider;

	/** @noinspection PhpUnused */

	/**
	 * @param Request $request
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function checktoken($userid) {

		$authInfo      = [];
		$resourceOwner = [];
		/**
		 * Get AuthInfo
		 */
		if (session::exists('authInfo')) {
			$authInfo = session::get('authInfo');
		} else {
			if (Storage::disk('local')->exists('ebay_tokeninfo_' . $userid)) {
				/** @noinspection PhpUnhandledExceptionInspection */
				$authInfo = \GuzzleHttp\json_decode(Storage::disk('local')->get('ebay_tokeninfo_' . $userid), true);
				session::put('authInfo', $authInfo);
			}
		}
		/**
		 * Get resourceOwner
		 */
		if (session::exists('resourceOwner')) {
			$resourceOwner = session::get('resourceOwner');
		} else {
			if (Storage::disk('local')->exists('ebay_userinfo_' . $userid)) {
				/** @noinspection PhpUnhandledExceptionInspection */
				$resourceOwner = \GuzzleHttp\json_decode(Storage::disk('local')->get('ebay_userinfo_' . $userid), true);
				session::put('resourceOwner', $resourceOwner);
			}
		}
		/**
		 * Check if request userid matches stored resourceOwner
		 */
		if (
			array_key_exists('User', $resourceOwner) &&
			$resourceOwner['User']['UserID'] !== $userid
		) {
			return view('welcome', [
				'auth_error' => 'UserID in session/disk (' . $resourceOwner['User']['UserID'] . ') does not match your request userid: ' . $userid
			]);
		}
		if (!count($authInfo)) {
			return view('welcome', [
				'auth_error' => 'unable to find any token infos'
			]);
		}

		$AccessToken = new EbayAccessToken(['expires' => $authInfo['expire'], 'access_token' => $authInfo['token']]);

		$resourceOwner = $this->getEbayOauthProvider()->getResourceOwner($AccessToken)->toArray();

		return view('welcome', [
			'auth_error'    => null,
			'authInfo'      => $authInfo,
			'resourceOwner' => $resourceOwner
		]);

	}

	/**
	 * @return Ebay
	 */
	private function getEbayOauthProvider(): Ebay {
		if (!$this->EbayOauthProvider) {
			$this->EbayOauthProvider = new Ebay([
													'clientId'       => env('EBAY_APP_ID'),
													'clientSecret'   => env('EBAY_CERT_ID'),
													'redirectUri'    => env('EBAY_RU_NAME'),
													'scopeSeparator' => ' ',
													'sandbox'        => false, //defaults to false, i.e. production
													'http_errors'    => false, // Optional. Means Guzzle Exceptions aren't thrown on 4xx or 5xx responses from eBay, allowing you to detect and handle them yourself
												], [
													'optionProvider' => new HttpBasicAuthOptionProvider()
												]);
		}

		return $this->EbayOauthProvider;
	}

	/**
	 * @param Request $request
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function refreshtoken(Request $request) {
		if (
			(session::get('isloggedin') === true) &&
			(session::exists('authInfo'))
		) {
			$provider = $this->getEbayOauthProvider();
			if ($request->get('force') || $this->hasExpired()) {
				try {
					$newAccessToken = $provider->getAccessToken('refresh_token', [
						'refresh_token' => \session('authInfo')['refreshtoken']
					]);
				} catch (IdentityProviderException $e) {
					return view('welcome', [
						'auth_error' => $e->getMessage()
					]);
				}

				list($authInfo, $resourceOwner) = $this->storeTokenInSessionAndDisk($newAccessToken, session::get('authInfo'));

				return view('welcome', [
					'auth_error'    => null,
					'authInfo'      => $authInfo,
					'resourceOwner' => $resourceOwner
				]);
			}

			return view('welcome', [
				'auth_error'    => 'token did not expire',
				'authInfo'      => session::get('authInfo'),
				'resourceOwner' => session::get('resourceOwner')
			]);

		}

		return view('welcome', [
			'auth_error' => 'seems like there exists no token'
		]);
	}

	private function hasExpired() {
		$authInfo    = session::get('authInfo');
		$AccessToken = new EbayAccessToken(['expires' => $authInfo['expire'], 'access_token' => 'fake']);

		return $AccessToken->hasExpired();
	}

	/**
	 * @param AccessTokenInterface $accessToken
	 *
	 * @return array
	 */
	private function storeTokenInSessionAndDisk(AccessTokenInterface $accessToken, array $oldAuthInfo = []): array {
		$authInfo                 = [];
		$authInfo['token']        = $accessToken->getToken();
		$authInfo['refreshtoken'] = ($accessToken->getRefreshToken()) ?: $oldAuthInfo['refreshtoken'];
		$authInfo['expire']       = $accessToken->getExpires();

		/** @noinspection PhpParamsInspection */
		$resourceOwner = $this->getEbayOauthProvider()->getResourceOwner($accessToken)->toArray();

		session([
					'isloggedin'    => true,
					'ebay_user_id'  => $resourceOwner['User']['UserID'],
					'resourceOwner' => $resourceOwner,
					'authInfo'      => $authInfo
				]);

		Storage::disk('local')->put('ebay_tokeninfo_' . $resourceOwner['User']['UserID'], \GuzzleHttp\json_encode($authInfo));
		Storage::disk('local')->put('ebay_userinfo_' . $resourceOwner['User']['UserID'], \GuzzleHttp\json_encode($resourceOwner));

		return [$authInfo, $resourceOwner];
	}

	/** @noinspection PhpUnused */
	public function logout() {
		{
			session::flush();
			session(['isloggedin' => false]);

			return view('welcome');
		}
	}

	/** @noinspection PhpUnused */
	public function welcome(Request $request) {

		$auth_error    = '';
		$authInfo      = [];
		$resourceOwner = [];

		if ($request->get('code') && session::get('isloggedin') === false) {

			$provider = $this->getEbayOauthProvider();

			try {
				$accessToken = $provider->getAccessToken('authorization_code', [
					'code' => $request->get('code')
				]);

				list($authInfo, $resourceOwner) = $this->storeTokenInSessionAndDisk($accessToken);

			} catch (IdentityProviderException $e) {
				$auth_error = $e->getMessage();
			}

		} elseif (session::get('isloggedin') === true) {
			/** @noinspection PhpUnhandledExceptionInspection */
//			$authInfo = \GuzzleHttp\json_decode(Storage::disk('local')->get('ebay_tokeninfo_' . session::get('ebay_user_id')), true);

			$authInfo = session::get('authInfo');

			$authInfo['is_expired'] = $this->hasExpired();

			/** @noinspection PhpUnhandledExceptionInspection */
//			$resourceOwner = \GuzzleHttp\json_decode(Storage::disk('local')->get('ebay_userinfo_' . session::get('ebay_user_id')), true);

			$resourceOwner = session::get('resourceOwner');
		}

		return view('welcome', [
			'auth_error'    => $auth_error,
			'authInfo'      => $authInfo,
			'resourceOwner' => $resourceOwner
		]);
	}

}
