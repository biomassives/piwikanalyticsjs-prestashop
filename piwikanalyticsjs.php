<?php
if (!defined('_PS_VERSION_'))
    exit;

/**
 * Copyright (C) 2016 Christian Jensen
 *
 * This file is part of PiwikAnalyticsJS for prestashop.
 * 
 * PiwikAnalyticsJS for prestashop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * PiwikAnalyticsJS for prestashop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with PiwikAnalyticsJS for prestashop.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @author Christian M. Jensen
 * @link http://cmjnisse.github.io/piwikanalyticsjs-prestashop
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * 
 * @todo config wiz, set currency to use.
 * @todo fix $keepURLFragments in add/update piwik site, this is not a boolean value but an int of 0=Use Global, 1=Yes or 2N=No
 * @todo allow user to go back in config wiz in case auth fails
 */
class piwikanalyticsjs extends Module {

    private static $_isOrder=FALSE;
    protected $_errors="";
    protected $piwikSite=FALSE;
    protected $default_currency=array();
    protected $currencies=array();

    /** @var float */
    protected $piwikVersion=0.0;

    /**
     * setReferralCookieTimeout
     */
    const PK_RC_TIMEOUT=262974;

    /**
     * setVisitorCookieTimeout
     */
    const PK_VC_TIMEOUT=569777;

    /**
     * setSessionCookieTimeout
     */
    const PK_SC_TIMEOUT=30;

    /**
     *
     * @var PiwikAnalyticsjsConfiguration 
     */
    public $config=null;

    public function __construct($name=null,$context=null) {
        $this->name='piwikanalyticsjs';
        $this->tab='analytics_stats';
        $this->version='0.8.3.d2'; //dX == dev number
        $this->author='Christian M. Jensen';
        $this->displayName='Piwik Analytics';
        $this->author_uri='https://cmjscripter.net';
        $this->url='http://cmjnisse.github.io/piwikanalyticsjs-prestashop/';
        $this->need_instance=1;

        /* version 1.5 uses invalid compatibility check */
        if (version_compare(_PS_VERSION_,'1.6.0.0','>='))
            $this->ps_versions_compliancy=array('min'=>'1.5','max'=>_PS_VERSION_);

        $this->bootstrap=true;

        parent::__construct($name,($context instanceof Context?$context:NULL));

        require_once dirname(__FILE__).'/PiwikAnalyticsjsConfiguration.php';
        require_once dirname(__FILE__).'/PKHelper.php';

        $this->config=& PKHelper::getConf();
        //* warnings on module list page
        if ($this->id&&!$this->config->token)
            $this->warning=(isset($this->warning)&&!empty($this->warning)?$this->warning.',<br/> ':'').$this->l('You need to configure the auth token');
        if ($this->id&&((int)$this->config->siteid<=0))
            $this->warning=(isset($this->warning)&&!empty($this->warning)?$this->warning.',<br/> ':'').$this->l('You have not yet set Piwik Site ID');
        if ($this->id&&!$this->config->host)
            $this->warning=(isset($this->warning)&&!empty($this->warning)?$this->warning.',<br/> ':'').$this->l('You need to configure the Piwik server url');

        $this->description=$this->l('Integrates Piwik Analytics into your shop');
        $this->confirmUninstall=$this->l('Are you sure you want to delete this plugin ?');

        self::$_isOrder=FALSE;
        PKHelper::$error="";
        $this->_errors=PKHelper::$errors=array();

        if ($this->id) {
            if (version_compare(_PS_VERSION_,'1.5.0.13',"<="))
                PKHelper::$_module=& $this;
        }
    }

    /**
     * get content to display in admin area
     * @return string
     */
    public function getContent() {
        $_html="";
        $this->setMedia();
        $this->processFormsUpdate();
        if (!$this->isWizardRequest())
            $this->piwikVersion=PKHelper::getPiwikVersion();
        if (Tools::isSubmit('submitPiwikAnalyticsjsWizard')) {
            $this->processWizardFormUpdate();
        }

        $this->piwikSite=false;
        if ($this->config->token!==false&&!$this->isWizardRequest())
            $this->piwikSite=PKHelper::getPiwikSite();

        $currencies=array();
        foreach (Currency::getCurrencies() as $key=> $val) {
            $currencies[$key]=array(
                'iso_code'=>$val['iso_code'],
                'name'=>"{$val['name']} {$val['iso_code']}",
            );
        }

        // warnings on module configure page
        if (!$this->isWizardRequest()) {
            if ($this->id&&!$this->config->token&&!Tools::getIsset(PACONF::PREFIX.'TOKEN_AUTH'))
                $this->_errors[]=$this->displayError($this->l('Piwik auth token is empty'));
            if ($this->id&&((int)$this->config->siteid<=0)&&!Tools::getIsset(PACONF::PREFIX.'SITEID'))
                $this->_errors[]=$this->displayError($this->l('Piwik site id is lower or equal to "0"'));
            if ($this->id&&!$this->config->host)
                $this->_errors[]=$this->displayError($this->l('Piwik host cannot be empty'));
        }

        $_currentIndex=AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules');

        // defaults
        $this->context->smarty->assign(array(
            'psversion'=>_PS_VERSION_,
            'pscurrentIndex'=>$_currentIndex,
            'pstoken'=>Tools::getAdminTokenLite('AdminModules'),
            'piwik_module_dir'=>__PS_BASE_URI__.'modules/'.$this->name,
            'pkCPREFIX'=>PACONF::PREFIX,
        ));
        if (version_compare(_PS_VERSION_,'1.5.0.5',">=")&&version_compare(_PS_VERSION_,'1.5.3.999',"<=")) {
            $this->context->smarty->assign(array('piwikAnalyticsControllerLink'=>$this->context->link->getAdminLink('PiwikAnalytics15')));
        } else if (version_compare(_PS_VERSION_,'1.5.0.13',"<=")) {
            $this->context->smarty->assign(array('piwikAnalyticsControllerLink'=>$this->context->link->getAdminLink('AdminPiwikAnalytics')));
        } else {
            $this->context->smarty->assign(array('piwikAnalyticsControllerLink'=>$this->context->link->getAdminLink('PiwikAnalytics')));
        }
        if ($this->isWizardRequest()) {
            return $this->runWizard($_currentIndex,$_html,$currencies);
        }
        
        $config_wizard_link=$this->context->link->getAdminLink('AdminModules').
                "&configure={$this->name}&tab_module=analytics_stats&module_name={$this->name}&pkwizard=1";

        // Piwik image tracking
        $image_tracking=array('default'=>false,'proxy'=>false);
        if (version_compare($this->piwikVersion,'2.1','>')) {
            if ($this->config->token===false||!($image_tracking=PKHelper::getPiwikImageTrackingCode()))
                $image_tracking=array('default'=>$this->l('I need Site ID and Auth Token before i can get your image tracking code'),
                    'proxy'=>$this->l('I need Site ID and Auth Token before i can get your image tracking code'));
        }
        $this->displayErrorsPiwik();

        // get cookie settings
        $PIWIK_RCOOKIE_TIMEOUT=(int)$this->config->RCOOKIE_TIMEOUT;
        $PIWIK_COOKIE_TIMEOUT=(int)$this->config->COOKIE_TIMEOUT;
        $PIWIK_SESSION_TIMEOUT=(int)$this->config->SESSION_TIMEOUT;

        $pktimezones=$this->getTimezonesList();


        $this->context->smarty->assign(array(
            'piwikVersion'=>$this->piwikVersion,
            'piwikSite'=>$this->piwikSite!==FALSE&&isset($this->piwikSite[0]),
            'piwikSiteId'=>(int)$this->config->siteid,
            'piwikSiteName'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->name:$this->l('unknown')),
            'piwikExcludedIps'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_ips:''),
            'piwikMainUrl'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->main_url:$this->l('unknown')),
            'config_wizard_link'=>$config_wizard_link,
            'tab_defaults_file'=>$this->_get_theme_file("tab_configure_defaults.tpl","views/templates/admin"),
            'tab_proxyscript_file'=>$this->_get_theme_file("tab_configure_proxy_script.tpl","views/templates/admin"),
            'tab_extra_file'=>$this->_get_theme_file("tab_configure_extra.tpl","views/templates/admin"),
            'tab_html_file'=>$this->_get_theme_file("tab_configure_html.tpl","views/templates/admin"),
            'tab_cookies_file'=>$this->_get_theme_file("tab_configure_cookies.tpl","views/templates/admin"),
            'tab_site_manager_file'=>$this->_get_theme_file("tab_site_manager.tpl","views/templates/admin"),
            /* Form values */
            /** tab|Site Manager * */
            'pktimezones'=>$pktimezones,
            'PKAdminSiteName'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->name:''),
            'PKAdminEcommerce'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->ecommerce:''),
            'PKAdminSiteSearch'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch:''),
            'PKAdminSearchKeywordParameters'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch_keyword_parameters:''),
            'PKAdminSearchCategoryParameters'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch_category_parameters:''),
            'SPKSID'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->idsite:$this->config->siteid),
            'PKAdminExcludedIps'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_ips:''),
            'PKAdminExcludedQueryParameters'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_parameters:''),
            'PKAdminTimezone'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->timezone:''),
            'PKAdminCurrency'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->currency:''),
            'PKAdminGroup'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->group:''),
            'PKAdminStartDate'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->ts_created:''),
            'PKAdminSiteUrls'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->main_url:''),
            'PKAdminExcludedUserAgents'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_user_agents:''),
            'PKAdminKeepURLFragments'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->keep_url_fragment:0),
            'PKAdminSiteType'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->type:'website'),
            /** tab|Defaults * */
            'pkfvHOST'=>$this->config->host,
            'pkfvSITEID'=>$this->config->siteid,
            'pkfvTOKEN_AUTH'=>$this->config->token,
            'pkfvUSE_CURL'=>$this->config->USE_CURL,
            'pkfvUSRNAME'=>$this->config->USRNAME,
            'pkfvUSRPASSWD'=>$this->config->USRPASSWD,
            'pkfvDNT'=>$this->config->DNT,
            'pkfvDEFAULT_CURRENCY'=>$this->config->DEFAULT_CURRENCY,
            'pkfvCurrencies'=>$currencies,
            'pkfvCURRENCY_DEFAULT'=>($this->piwikSite!==FALSE?$this->piwikSite[0]->currency:$this->l('unknown')),
            'pkfvDREPDATE'=>$this->config->DREPDATE,
            /** tab|Proxy Script * */
            'pkfvPROXY_TIMEOUT'=>$this->config->PROXY_TIMEOUT,
            'pkfvUSE_PROXY'=>$this->config->USE_PROXY,
            'pkfvPROXY_SCRIPTPlaceholder'=>str_replace(array("http://","https://"),'',self::getModuleLink($this->name,'piwik')),
            'pkfvPROXY_SCRIPTBuildIn'=>str_replace(array("http://","https://"),'',self::getModuleLink($this->name,'piwik')),
            'pkfvPROXY_SCRIPT'=>$this->config->PROXY_SCRIPT,
            'has_cURL'=>(!function_exists('curl_version') /* FIX:4 my Nginx (php-fpm) */&&!function_exists('curl_init')/* //FIX */)?false:true,
            'pkfvPAUTHUSR'=>$this->config->PAUTHUSR,
            'pkfvPAUTHPWD'=>$this->config->PAUTHPWD,
            'pkfvCRHTTPS'=>$this->config->CRHTTPS,
            'pkfvFIX401_1'=>(int)Configuration::get(PACONF::PREFIX."FIX401_1"),
            /** tab|Extra * */
            'pkfvPRODID_V1'=>$this->getProductIdTemplate(1),
            'pkfvPRODID_V2'=>$this->getProductIdTemplate(2),
            'pkfvPRODID_V3'=>$this->getProductIdTemplate(3),
            'pkfvSEARCH_QUERY'=>$this->config->SEARCH_QUERY,
            'pkfvSET_DOMAINS'=>$this->config->SET_DOMAINS,
            'pkfvDHASHTAG'=>$this->config->DHASHTAG,
            'pkfvAPTURL'=>$this->config->APTURL,
            /** tab|HTML * */
            'pkfvEXHTML'=>$this->config->EXHTML,
            'pkfvEXHTML_ImageTracker'=>$image_tracking['default'],
            'pkfvEXHTML_ImageTrackerProxy'=>$image_tracking['proxy'],
            'pkfvEXHTML_Warning'=>(version_compare(_PS_VERSION_,'1.6.0.7','>=')?"<br><strong>{$this->l("Before you edit/add html code to this field make sure the HTMLPurifier library isn't in use if HTMLPurifier is enabled, all html code will be stripd from the field when saving, check the settings in 'Preferences=>General', you can enable HTMLPurifier again after you made your changes")}</strong>":""),
            'pkfvLINKTRACK'=>$this->config->LINKTRACK,
            'pkfvLINKClS'=>$this->config->LINKCLS,
            'pkfvLINKClSIGNORE'=>$this->config->LINKCLSIGNORE,
            'pkfvLINKTTIME'=>(int)$this->config->LINKTTIME,
            /** tab|Cookies * */
            'pkfvSESSION_TIMEOUT'=>($PIWIK_SESSION_TIMEOUT!=0?(int)($PIWIK_SESSION_TIMEOUT/60):(int)(self::PK_SC_TIMEOUT )),
            'pkfvCOOKIE_TIMEOUT'=>($PIWIK_COOKIE_TIMEOUT!=0?(int)($PIWIK_COOKIE_TIMEOUT/60):(int)(self::PK_VC_TIMEOUT)),
            'pkfvRCOOKIE_TIMEOUT'=>($PIWIK_RCOOKIE_TIMEOUT!=0?(int)($PIWIK_RCOOKIE_TIMEOUT/60):(int)(self::PK_RC_TIMEOUT)),
            'pkfvCOOKIE_DOMAIN'=>$this->config->COOKIE_DOMAIN,
            'pkfvCOOKIEPREFIX'=>$this->config->COOKIEPREFIX,
            'pkfvCOOKIEPATH'=>$this->config->COOKIEPATH,
        ));
        $_html .= $this->display(__FILE__,'views/templates/admin/configure_tabs.tpl');

        if (is_array($this->_errors))
            $_html=implode('',$this->_errors).$_html;
        else
            $_html=$this->_errors.$_html;

        return $_html.$this->display(__FILE__,'views/templates/admin/jsfunctions.tpl');
    }

    private function runWizard($_currentIndex,$_html,$currencies) {
        require_once dirname(__FILE__).'/PiwikWizard.php';
        // set translations (done this way to make translations work cross classes)
        PiwikWizardHelper::$strings['4ddd9129714e7146ed2215bcbd559335']=$this->l("I encountered an unknown error while trying to get the selected site, id #%s");
        PiwikWizardHelper::$strings['e41246ca9fd83a123022c5c5b7a6f866']=$this->l("I'm unable, to get admin access to the selected site id #%s");
        PiwikWizardHelper::$strings['8a7d6b386e97596cb28878e9be5804b8']=$this->l("Piwik sitename is missing");
        PiwikWizardHelper::$strings['7948cb754538ab57b44c956c22aa5517']=$this->l("Piwik main url is missing");
        PiwikWizardHelper::$strings['0f30ab07916f20952f2e6ef70a91d364']=$this->l("Piwik currency is missing");
        PiwikWizardHelper::$strings['f7472169e468dd1fd901720f4bae1957']=$this->l("Piwik timezone is missing");
        PiwikWizardHelper::$strings['75e263846f84003f0180137e79542d38']=$this->l("Error while creating site in piwik please check the following messages for clues");
        // start wizard process
        PiwikWizardHelper::setUsePiwikSite($_currentIndex);
        PiwikWizardHelper::createNewSite();
        $step=1;
        if (empty($this->_errors)&&empty($_html)) {
            if (Tools::getIsset(PACONF::PREFIX.'STEP_WIZARD')) {
                $step=(int)Tools::getValue(PACONF::PREFIX.'STEP_WIZARD',0);
                if (!Tools::isSubmit('createnewsite'))
                    $step++;
                else
                    $step=2;
            }
        }

        $pkToken=null;
        if ($step==2) {
            PiwikWizardHelper::getFormValuesInternal(true);
            $pkToken=PKHelper::getTokenAuth(PiwikWizardHelper::$username,PiwikWizardHelper::$password);
            if (!empty(PKHelper::$error)) {
                $step=1;
            } else {
                if ($pkToken!==false) {
                    if (Tools::isSubmit('createnewsite')) {
                        $step=99;
                    } else {
                        if ($_pkSites=PKHelper::getSitesWithAdminAccess(false,array(
                                    'idSite'=>0,'pkHost'=>PKHelper::$piwikHost,
                                    'https'=>(strpos(PiwikWizardHelper::$piwikhost,'https://')!==false),
                                    'pkModule'=>'API','isoCode'=>NULL,'tokenAuth'=>$pkToken))) {
                            if (!empty($_pkSites)) {
                                $pkSites=array();
                                foreach ($_pkSites as $value) {
                                    $pkSites[]=array(
                                        'idsite'=>$value->idsite,
                                        'name'=>$value->name,
                                        'main_url'=>$value->main_url,
                                    );
                                }
                                $this->context->smarty->assign(array('pkSites'=>$pkSites));
                            }
                            unset($_pkSites);
                        }
                        if (!empty(PKHelper::$error)) {
                            $step=1;
                        }
                    }
                } else {
                    $step=1;
                    $this->_errors[]=$this->displayError($this->l("Unknown error, Piwik auth token returned NULL, check your username and password"));
                }
            }
        }
        $saved_piwik_host=$this->config->host;
        if (!empty($saved_piwik_host)) {
            $saved_piwik_host="http://".$saved_piwik_host;
        }
        $this->context->smarty->assign(array(
            'pscurrentIndex'=>$_currentIndex.'&pkwizard',
            'pscurrentIndexLnk'=>$_currentIndex,
            'wizardStep'=>$step,
            'pkfvHOST'=>$saved_piwik_host,
        ));

        if ($step>=2) {
            $this->context->smarty->assign(array(
                'pkfvHOST_WIZARD'=>PiwikWizardHelper::$piwikhost,
                'pkfvUSRNAME_WIZARD'=>PiwikWizardHelper::$username,
                'pkfvUSRPASSWD_WIZARD'=>PiwikWizardHelper::$password,
                'pkfvPAUTHUSR_WIZARD'=>PiwikWizardHelper::$usernamehttp,
                'pkfvPAUTHPWD_WIZARD'=>PiwikWizardHelper::$passwordhttp,
            ));
            $Currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
            if (!is_object($Currency)||!Validate::isLoadedObject($Currency)) {
                $Currency=new stdClass();
                $Currency->iso_code='EUR';
            }
            $this->context->smarty->assign(array(
                'PKNewSiteName'=>Configuration::get('PS_SHOP_NAME'),'PKNewAddtionalUrls'=>'','PKNewEcommerce'=>1,
                'PKNewSiteSearch'=>1,'PKNewKeepURLFragments'=>0,'PKNewTimezone'=>'UTC',
                'PKNewSearchKeywordParameters'=>'','PKNewExcludedUserAgents'=>'','PKNewSearchCategoryParameters'=>'',
                'PKNewCurrency'=>Context::getContext()->currency->iso_code,'PKNewExcludedQueryParameters'=>'',
                'PKNewMainUrl'=>Tools::getShopDomainSsl(true,true).Context::getContext()->shop->getBaseURI(),
                'PKNewExcludedIps'=>Configuration::get('PS_MAINTENANCE_IP'),
                'pkfvMyIPis'=>$_SERVER['REMOTE_ADDR'],
                'pkfvTimezoneList'=>$this->getTimezonesList($pkToken,PiwikWizardHelper::$piwikhost),
                'pkfvCurrencies'=>$currencies,
                'PKNewCurrency'=>$Currency->iso_code,
            ));
            unset($Currency);
        }
        $this->displayErrorsPiwik();
        $this->displayErrorsPiwikWizard();
        if (is_array($this->_errors))
            $_html=implode('',$this->_errors).$_html;
        else
            $_html=$this->_errors.$_html;
        return $_html
                .$this->display(__FILE__,'views/templates/admin/piwik_wizard.tpl')
                .$this->display(__FILE__,'views/templates/admin/jsfunctions.tpl')
                .$this->display(__FILE__,'views/templates/admin/piwik_site_lookup.tpl');
    }

    /**
     * handles the wizzard configuration form processing
     * @return void
     */
    private function processWizardFormUpdate() {
        $KEY_PREFIX=PACONF::PREFIX;
        $username=$password=$piwikhost=$saveusrpwd=$usernamehttp=$passwordhttp=false;
        if (Tools::getIsset($KEY_PREFIX.'USRNAME_WIZARD'))
            $username=Tools::getValue($KEY_PREFIX.'USRNAME_WIZARD');
        if (Tools::getIsset($KEY_PREFIX.'USRPASSWD_WIZARD'))
            $password=Tools::getValue($KEY_PREFIX.'USRPASSWD_WIZARD');
        if (Tools::getIsset($KEY_PREFIX.'HOST_WIZARD'))
            $piwikhost=Tools::getValue($KEY_PREFIX.'HOST_WIZARD');
        if (Tools::getIsset($KEY_PREFIX.'SAVE_USRPWD_WIZARD'))
            $saveusrpwd=(bool)Tools::getValue($KEY_PREFIX.'SAVE_USRPWD_WIZARD',false);
        if (Tools::getIsset($KEY_PREFIX.'PAUTHUSR_WIZARD'))
            $usernamehttp=Tools::getValue($KEY_PREFIX.'PAUTHUSR_WIZARD',"");
        if (Tools::getIsset($KEY_PREFIX.'PAUTHPWD_WIZARD'))
            $passwordhttp=Tools::getValue($KEY_PREFIX.'PAUTHPWD_WIZARD',"");
        if ($piwikhost!==false&&!empty($piwikhost)) {
            $tmp=$piwikhost;
            if (PKHelper::isValidUrl($tmp)||PKHelper::isValidUrl('http://'.$tmp)) {
                if (preg_match("/https:/i",$tmp))
                    $this->config->update('CRHTTPS',1);
                $tmp=str_ireplace(array('http://','https://','//'),"",$tmp);
                if (substr($tmp,-1)!="/") {
                    $tmp .= "/";
                }
                $this->config->update('HOST',$tmp);
            } else {
                $this->_errors[]=$this->displayError($this->l('Piwik host url is not valid'));
            }
        } else {
            $this->_errors[]=$this->displayError($this->l('Piwik host cannot be empty'));
        }
        if (($username!==false&&strlen($username)>2)&&($password!==false&&strlen($password)>2)) {
            if ($saveusrpwd==1||$saveusrpwd!==false) {
                $this->config->update("USRPASSWD",$password);
                $this->config->update("USRNAME",$username);
            }
        } else {
            $this->_errors[]=$this->displayError($this->l('Username and/or password is missing/to short'));
        }
        if (($usernamehttp!==false&&strlen($usernamehttp)>0)&&($passwordhttp!==false&&strlen($passwordhttp)>0)) {
            $this->config->update("PAUTHUSR",$usernamehttp);
            $this->config->update("PAUTHPWD",$passwordhttp);
        }
    }

    /**
     * handles the configuration form update
     * @return void
     */
    private function processFormsUpdate() {
        $isPost=false;
        $KEY_PREFIX=PACONF::PREFIX;
        // handle submission from defaults tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsDefaults')) {
            $isPost=true;
            // [PIWIK_HOST] Piwik host URL
            if (!$this->config->validate_save_host("HOST")) {
                if (PKHelper::isNullOrEmpty($this->config->host))
                    $this->_errors[]=$this->displayError($this->l('Piwik host cannot be empty'));
                else
                    $this->_errors[]=$this->displayError(sprintf($this->l('Piwik host url is not valid (%s)'),$this->config->host));
            }
            // [PIWIK_SITEID] Piwik site id
            if (!$this->config->validate_save_siteid("SITEID"))
                $this->_errors[]=$this->displayError(sprintf($this->l('Piwik site id is not valid (%s)'),$this->config->siteid));
            // [PIWIK_TOKEN_AUTH] Piwik authentication token
            if (!$this->config->validate_save_token("TOKEN_AUTH"))
                $this->_errors[]=$this->displayError(sprintf($this->l('Piwik auth token is not valid (%s)'),$this->config->token));
            // [PIWIK_DNT]
            if (!$this->config->validate_save_isset_boolean_dnt("DNT"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("DoNotTrack")));
            // [PIWIK_DEFAULT_CURRENCY]
            if (!$this->config->validate_save_currency("DEFAULT_CURRENCY"))
                $this->_errors[]=$this->displayError(sprintf($this->l('Currency is not valid (%s)'),$this->config->currency));
            // [PIWIK_DREPDATE] (default report date)
            if (!$this->config->validate_save_drepdate("DREPDATE"))
                $this->_errors[]=$this->displayError(sprintf($this->l('Piwik Report date is not valid (%s)'),$this->config->drepdate));

            /* don't validate there is no default requirements */
            // [PIWIK_USRNAME] 
            if (Tools::getIsset('username_changed')&&(((int)Tools::getValue('username_changed'))==1)&&Tools::getIsset($KEY_PREFIX.'USRNAME')) {
                $this->config->update("USRNAME",Tools::getValue($KEY_PREFIX.'USRNAME',''));
            }
            // [PIWIK_USRPASSWD] 
            if (Tools::getIsset('password_changed')&&(((int)Tools::getValue('password_changed'))==1)&&Tools::getIsset($KEY_PREFIX.'USRPASSWD')) {
                $this->config->update("USRPASSWD",Tools::getValue($KEY_PREFIX.'USRPASSWD',''));
            }
        }
        // handle submission from proxy tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsProxyScript')) {
            $isPost=true;
            // [PIWIK_USE_PROXY] 
            if (!$this->config->validate_save_isset_boolean_useproxy("USE_PROXY"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Use proxy script")));
            // [PIWIK_USE_CURL] 
            if (!$this->config->validate_save_isset_boolean_usecurl("USE_CURL"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Use cURL")));
            // [PIWIK_CRHTTPS] 
            if (!$this->config->validate_save_isset_boolean_usehttps("CRHTTPS"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Use HTTPS")));
            // [PIWIK_PROXY_TIMEOUT] 
            if (!$this->config->validate_save_isint_proxytimeout("PROXY_TIMEOUT",1,5))
                $this->_errors[]=$this->displayError($this->l('Proxy timeout validation error, must be an integer and larger than 0 (zero), timeout has been set to 5 (five)'));
            // [PROXY_SCRIPT] 
            if (!$this->config->validate_save_proxyscript('PROXY_SCRIPT')) {
                if ($this->config->useproxy) /* only show error if we are supposed to be using proxy script */
                    $this->_errors[]=$this->displayError(sprintf($this->l('Proxy script url is not valid (%s)'),$this->config->proxyscript));
            }

            /* don't validate there is no default requirements */
            // [PIWIK_PAUTHUSR] 
            if (Tools::getIsset('pusername_changed')&&(((int)Tools::getValue('pusername_changed'))==1)&&Tools::getIsset($KEY_PREFIX.'PAUTHUSR')) {
                $this->config->update("PAUTHUSR",Tools::getValue($KEY_PREFIX.'PAUTHUSR',''));
            }
            // [PIWIK_PAUTHPWD] 
            if (Tools::getIsset('ppassword_changed')&&(((int)Tools::getValue('ppassword_changed'))==1)&&Tools::getIsset($KEY_PREFIX.'PAUTHPWD')) {
                $this->config->update("PAUTHPWD",Tools::getValue($KEY_PREFIX.'PAUTHPWD',''));
            }
            if (Tools::getIsset($KEY_PREFIX.'FIX401_1'))
                Configuration::updateValue($KEY_PREFIX."FIX401_1",1);
            else
                Configuration::updateValue($KEY_PREFIX."FIX401_1",0);
        }
        // handle submission from extra tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsExtra')) {
            $isPost=true;
            // [PIWIK_PRODID_V1] 
            if (!$this->config->validate_save_producttplv1('PRODID_V1'))
                $this->__validateHelperProductId(1);
            // [PIWIK_PRODID_V2] 
            if (!$this->config->validate_save_producttplv2('PRODID_V2'))
                $this->__validateHelperProductId(2);
            // [PIWIK_PRODID_V3] 
            if (!$this->config->validate_save_producttplv3('PRODID_V3'))
                $this->__validateHelperProductId(3);
            // [PIWIK_SEARCH_QUERY]
            if (!$this->config->validate_save_searchquery('SEARCH_QUERY')) {
                if (isset($this->config->validate_output['QUERY']))
                    $this->_errors[]=$this->displayError($this->l('Search template: missing variable {QUERY}'));
            } else if (isset($this->config->validate_output['PAGE']))
                $this->_errors[]=$this->displayWarning($this->l('Search template: missing optional variable {PAGE}'));

            // [PIWIK_SET_DOMAINS]
            if (!$this->config->validate_save_setdomains('SET_DOMAINS'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Hide known alias URLs")));
            // [PIWIK_DHASHTAG] 
            if (!$this->config->validate_save_isset_boolean_dhashtag("DHASHTAG"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Discard hash tag")));
            // [PIWIK_APTURL] 
            if (!$this->config->validate_save_isset_boolean_apiurl("APTURL"/* "APIURL */))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Set api url")));
        }
        // handle submission from html tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsHTML')) {
            $isPost=true;
            // [PIWIK_EXHTML]
            if (!$this->config->validate_save_exhtml('EXHTML'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Extra HTML")));
            // [PIWIK_LINKTRACK] 
            if (!$this->config->validate_save_isset_boolean_linktrack("LINKTRACK"))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Enable link tracking")));
            // [PIWIK_LINKClS] 
            if (!$this->config->validate_save_linkcls('LINKClS'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Link classes")));
            // [PIWIK_LINKClSIGNORE] 
            if (!$this->config->validate_save_linkclsignore('LINKClSIGNORE'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Ignore link classes")));
            // [PIWIK_LINKTTIME] 
            if (!$this->config->validate_save_isint_linkttime("LINKTTIME",0,0))
                $this->_errors[]=$this->displayError($this->l('Link tracking timer validation error, must be a positive integer, timeout has been set to 0 (zero) using piwik default value'));
        }
        // handle submission from cookies tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsCookies')) {
            $isPost=true;
            // [PIWIK_COOKIE_DOMAIN]
            if (!$this->config->validate_save_cookiedomain('COOKIE_DOMAIN'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Track visitors across subdomains")));
            // [PIWIK_COOKIEPREFIX]
            if (!$this->config->validate_save_cookieprefix('COOKIEPREFIX'))
                $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Cookie name prefix")));
            // [PIWIK_COOKIEPATH]
            if (!$this->config->validate_save_cookiepath('COOKIEPATH')) {
                if ($this->config->validate_output=='/') {
                    $this->_errors[]=$this->displayError(sprintf($this->l('Cookie path must start with "/" or be empty')));
                } else {
                    $this->_errors[]=$this->displayError(sprintf($this->l('%s were not saved, internal unknown system error'),$this->l("Cookie path")));
                }
            }
            // [PIWIK_RCOOKIE_TIMEOUT]
            if (!$this->config->validate_save_isint_rcookietimeout("RCOOKIE_TIMEOUT",0,(self::PK_RC_TIMEOUT*60)))
                $this->_errors[]=$this->displayError($this->l('Referral Cookie timeout validation error, must be an positive integer, timeout has been set to default value of 6 months'));
            // [PIWIK_COOKIE_TIMEOUT]
            if (!$this->config->validate_save_isint_cookietimeout("COOKIE_TIMEOUT",0,(self::PK_VC_TIMEOUT*60)))
                $this->_errors[]=$this->displayError($this->l('Visitor Cookie timeout validation error, must be an positive integer, timeout has been set to default value of 13 months'));
            // [PIWIK_SESSION_TIMEOUT]
            if (!$this->config->validate_save_isint_sessiontimeout("SESSION_TIMEOUT",0,(self::PK_SC_TIMEOUT*60)))
                $this->_errors[]=$this->displayError($this->l('Session Cookie timeout validation error, must be an positive integer, timeout has been set to default value of 30 minutes'));
        }
        // handle submission from site manager tab
        if (Tools::isSubmit('submitUpdatePiwikAnalyticsjsSiteManager')) {
            $isPost=true;
            $PKAdminIdSite=(int)$this->config->siteid;
            $PKAdminGroup=($this->piwikSite!==FALSE?$this->piwikSite[0]->group:'');
            //$PKAdminStartDate = ($this->piwikSite !== FALSE ? $this->piwikSite[0]->ts_created : '');
            $PKAdminStartDate=NULL;
            //$PKAdminSiteUrls = ($this->piwikSite !== FALSE ? $this->piwikSite[0]->main_url : '');
            $PKAdminSiteUrls=PKHelper::getSiteUrlsFromId($PKAdminIdSite);
            $PKAdminSiteName=($this->piwikSite!==FALSE?$this->piwikSite[0]->name:$this->l('unknown'));
            $PKAdminEcommerce=($this->piwikSite!==FALSE?$this->piwikSite[0]->ecommerce:'');
            $PKAdminSiteSearch=($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch:'');
            $PKAdminSearchKeywordParameters=($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch_keyword_parameters:'');
            $PKAdminSearchCategoryParameters=($this->piwikSite!==FALSE?$this->piwikSite[0]->sitesearch_category_parameters:'');
            $PKAdminExcludedIps=($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_ips:'');
            $PKAdminExcludedQueryParameters=($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_parameters:'');
            $PKAdminTimezone=($this->piwikSite!==FALSE?$this->piwikSite[0]->timezone:'');
            $PKAdminCurrency=($this->piwikSite!==FALSE?$this->piwikSite[0]->currency:'');
            $PKAdminExcludedUserAgents=($this->piwikSite!==FALSE?$this->piwikSite[0]->excluded_user_agents:'');
            $PKAdminKeepURLFragments=($this->piwikSite!==FALSE?$this->piwikSite[0]->keep_url_fragment:0);
            $PKAdminSiteType=($this->piwikSite!==FALSE?$this->piwikSite[0]->type:'website');
            // [PKAdminGroup]
//            if (Tools::getIsset('PKAdminGroup')) {
//                $PKAdminGroup = Tools::getValue('PKAdminGroup',$PKAdminGroup);
//            }
            // [PKAdminStartDate]
//            if (Tools::getIsset('PKAdminStartDate')) {
//                $PKAdminStartDate = Tools::getValue('PKAdminStartDate',$PKAdminStartDate);
//            }
            // [PKAdminSiteUrls]
//            if (Tools::getIsset('PKAdminSiteUrls')) {
//                $PKAdminSiteUrls = Tools::getValue('PKAdminSiteUrls',$PKAdminSiteUrls);
//            }
            // [PKAdminSiteType]
//            if (Tools::getIsset('PKAdminSiteType')) {
//                $PKAdminSiteType = Tools::getValue('PKAdminSiteType',$PKAdminSiteType);
//            }
            // [PKAdminSiteName]
            if (Tools::getIsset('PKAdminSiteName')) {
                $PKAdminSiteName=Tools::getValue('PKAdminSiteName',$PKAdminSiteName);
                if (!Validate::isString($PKAdminSiteName)||empty($PKAdminSiteName))
                    $this->_errors[]=$this->displayError($this->l('SiteName is not valid'));
            }
            // [PKAdminEcommerce]
            if (Tools::getIsset('PKAdminEcommerce')) {
                $PKAdminEcommerce=true;
            } else {
                $PKAdminEcommerce=false;
            }
            // [PKAdminSiteSearch]
            if (Tools::getIsset('PKAdminSiteSearch')) {
                $PKAdminSiteSearch=true;
            } else {
                $PKAdminSiteSearch=false;
            }
            // [$PKAdminSearchKeywordParameters]
            if (Tools::getIsset('PKAdminSearchKeywordParameters')) {
                $PKAdminSearchKeywordParameters=Tools::getValue('PKAdminSearchKeywordParameters',$PKAdminSearchKeywordParameters);
            }
            // [PKAdminSearchCategoryParameters]
            if (Tools::getIsset('PKAdminSearchCategoryParameters')) {
                $PKAdminSearchCategoryParameters=Tools::getValue('PKAdminSearchCategoryParameters',$PKAdminSearchCategoryParameters);
            }
            // [PKAdminExcludedIps]
            if (Tools::getIsset('PKAdminExcludedIps')) {
                $PKAdminExcludedIps="";
                $tmp=Tools::getValue('PKAdminExcludedIps',$PKAdminExcludedIps);
                foreach (explode(',',$tmp) as $value) {
                    if (PKHelper::isIPv4($value)||PKHelper::isIPv6($value))
                        $PKAdminExcludedIps .= $value.',';
                    else
                        $this->_errors[]=$this->displayError(sprintf($this->l('Error excluded ip "%s" is not valid'),$value));
                }
                $PKAdminExcludedIps=trim($PKAdminExcludedIps,',');
            }
            // [PKAdminExcludedQueryParameters]
            if (Tools::getIsset('PKAdminExcludedQueryParameters')) {
                $PKAdminExcludedQueryParameters=Tools::getValue('PKAdminExcludedQueryParameters',$PKAdminExcludedQueryParameters);
            }
            // [PKAdminTimezone]
            if (Tools::getIsset('PKAdminTimezone')) {
                $PKAdminTimezone=Tools::getValue('PKAdminTimezone',$PKAdminTimezone);
            }
            // [PKAdminCurrency]
            if (Tools::getIsset('PKAdminCurrency')) {
                $PKAdminCurrency=Tools::getValue('PKAdminCurrency',$PKAdminCurrency);
            }
            // [PKAdminExcludedUserAgents]
            if (Tools::getIsset('PKAdminExcludedUserAgents')) {
                $PKAdminExcludedUserAgents=Tools::getValue('PKAdminExcludedUserAgents',$PKAdminExcludedUserAgents);
            }
            // [PKAdminKeepURLFragments]
            if (Tools::getIsset('PKAdminKeepURLFragments')) {
                $PKAdminKeepURLFragments=true;
            } else {
                $PKAdminKeepURLFragments=false;
            }
            $result=PKHelper::updatePiwikSite(
                    $PKAdminIdSite,$PKAdminSiteName,$PKAdminSiteUrls,
                    $PKAdminEcommerce,$PKAdminSiteSearch,
                    $PKAdminSearchKeywordParameters,
                    $PKAdminSearchCategoryParameters,$PKAdminExcludedIps,
                    $PKAdminExcludedQueryParameters,$PKAdminTimezone,
                    $PKAdminCurrency,$PKAdminGroup,$PKAdminStartDate,
                    $PKAdminExcludedUserAgents,$PKAdminKeepURLFragments,
                    $PKAdminSiteType);
            if ($result === false) {
                $this->displayErrors(PKHelper::$errors);
                PKHelper::$errors=PKHelper::$error="";
            }
        }
        if ($isPost) {
            if (count($this->_errors))
                $this->_errors[]=$this->displayConfirmation($this->l('Configuration updated, but contains errors/warnings'));
            else
                $this->_errors[]=$this->displayConfirmation($this->l('Configuration updated successfully'));
        }
    }

    /* HOOKs */

    public function hookActionProductCancel($params) {
        /*
         * @todo research [ps 1.6]
         * admin hook, wonder if this can be implemented
         * remove a product from the cart in Piwik
         * 
         * $params = array('order'=>obj [Order], 'id_order_detail'=>int)
         * 
         * if (version_compare(_PS_VERSION_, '1.5', '>=')
         *     $this->registerHook('actionProductCancel')
         */
    }

    public function hookProductFooter($params) {
        /**
         * @todo research
         * use for product views, keeping hookFooter as simple as possible
         * $params = array('product'=>$product, 'category'=>$category)
         * displayFooterProduct ?? [array('product'=>obj, 'category'=>obj)]
         * 
         * $this->registerHook('productfooter')
         */
    }

    /**
     * experimental .. experimental
     * submit cart updates when they occur, this allows for all carts to be tracked without the need for users to reload/continue navigating the site
     * but the current status is experimental, if ANY timeout occurs the users browser can lock up, and throw javascript error to the client, and they will not like your site after that..!
     * @return void
     * @remarks always use the latest revision of PiwikTracker, not included you must download and upload it your self..
     * @link http://piwik.org/docs/tracking-api/ tracking docs
     * @link https://github.com/piwik/piwik-php-tracker Piwik PHP tracking client source code
     * @since 0.8.4
     */
    public function hookActionCartSave() {
        if (!isset($this->context->cart))
            return;
        if (!class_exists('PiwikTracker',false)&&file_exists(dirname(__FILE__).'/piwik-php-tracker/PiwikTracker.php')) {
            require_once dirname(__FILE__).'/piwik-php-tracker/PiwikTracker.php';
        }
        try { /* no need to get errors here it will disrupt the customers shopping experience */
            $t=null;
            if (class_exists('PiwikTracker',false)) {
                $t=new PiwikTracker($this->config->site_id,$this->config->getPiwikUrl(true));
                //$t->setVisitorId($visitorId);
                /*
                 * to set cookieprefix ($this->config->cookieprefix)
                 * you need to modify const FIRST_PARTY_COOKIES_PREFIX to match your settings
                 * DEFAULT: const FIRST_PARTY_COOKIES_PREFIX = '_pk_';
                 */
                $t->setBrowserHasCookies(true);
                $cookiedomain = $this->config->cookiedomain;
                if (PKHelper::isNullOrEmpty($cookiedomain))
                    $cookiedomain = Tools::getShopDomain();
                $t->enableCookies($this->config->cookiedomain,$this->config->cookiepath);
                $t->setTokenAuth($this->config->token);
                $t->setRequestTimeout((int)$this->config->proxytimeout);
            } else {
                return;
            }
            
            // if hook is called something in db is updated so no check on the time.!
            $date_upd=strtotime($this->context->cart->date_upd);
            $this->context->cookie->PIWIKTrackCartFooter=$date_upd+2;
            $Currency=new Currency($this->context->cart->id_currency);
            $cart=$this->getCartProducts($Currency);

            // we could do this if the user is logged in!!
            //$t->setCity($city);
            //$t->setCountry($country);
            
            if (isset($_SERVER['HTTP_USER_AGENT'])&&!empty($_SERVER['HTTP_USER_AGENT']))
                $t->setUserAgent($_SERVER['HTTP_USER_AGENT']);
            if (isset($_SERVER['REMOTE_ADDR'])&&!empty($_SERVER['REMOTE_ADDR']))
                $t->setIp($_SERVER['REMOTE_ADDR']);
            if (version_compare(_PS_VERSION_,'1.5','<')&&$this->context->cookie->isLogged()) {
                $t->setUserId($this->context->cookie->id_customer);
            } else if ($this->context->customer->isLogged()) {
                $t->setUserId($this->context->customer->id);
            }
            $t->setUrl(Tools::getShopDomain(true).$_SERVER['REQUEST_URI']);
            $t->setUrlReferrer($_SERVER['HTTP_REFERER']);

            if (count($cart)>0) {
                foreach ($cart as $pk=> $pv) {
                    $t->addEcommerceItem($pv['SKU'],$pv['NAME'],$pv['CATEGORY'],$pv['PRICE'],$pv['QUANTITY']);
                }
            }
            $result = $t->doTrackEcommerceCartUpdate(
                    $this->currencyConvertion(
                            array(
                                'price'=>$this->context->cart->getOrderTotal(),
                                'conversion_rate'=>$Currency->conversion_rate)));
            PKHelper::DebugLogger("hookActionCartSave: result = {$result}");// should be a 1x1 gif..
        } catch (Exception $exc) {
            PKHelper::ErrorLogger($exc->getMessage()." : ".$exc->getFile().":".$exc->getLine());
        }
    }

    /**
     * hook into maintenance page.
     * @param array $params empty array
     * @return string
     * @since 0.8
     */
    public function hookdisplayMaintenance($params) {
        return $this->hookFooter($params);
    }

    /**
     * PIWIK don't track links on the same site eg. 
     * if product is view in an iframe so we add this and makes sure that it is content only view 
     * @param mixed $param
     * @return string
     */
    public function hookdisplayRightColumnProduct($param) {
        if ((int)$this->config->siteid<=0)
            return "";
        if ((int)Tools::getValue('content_only')>0&&get_class($this->context->controller)=='ProductController') {
            return $this->hookFooter($param);
        }
    }

    /**
     * Search action
     * @param array $param
     */
    public function hookactionSearch($param) {
        if ((int)$this->config->siteid<=0)
            return "";
        $param['total']=intval($param['total']);
        // $param['expr'] is not the searched word if lets say search is Snitmøntre then the $param['expr'] will be Snitmontre
        $expr=Tools::getIsset('search_query')?htmlentities(Tools::getValue('search_query')):$param['expr'];
        /* if multi pages in search add page number of current if set! */
        $search_tpl=$this->config->SEARCH_QUERY;
        if ($search_tpl===false)
            $search_tpl="{QUERY} ({PAGE})";
        if (Tools::getIsset('p')) {
            $search_tpl=str_replace('{QUERY}',$expr,$search_tpl);
            $expr=str_replace('{PAGE}',Tools::getValue('p'),$search_tpl);
        }

        $this->context->smarty->assign(array(
            PACONF::PREFIX.'SITE_SEARCH'=>"_paq.push(['trackSiteSearch',\"{$expr}\",false,{$param['total']}]);"
        ));
    }

    /**
     * only checks that the module is registered in hook "footer", 
     * this way we only append javescript to the end of the page!
     * @param mixed $params
     */
    public function hookHeader($params) {
        if (!$this->isRegisteredInHook('footer'))
            $this->registerHook('footer');
    }

    public function hookOrderConfirmation($params) {
        if ((int)$this->config->siteid<=0)
            return "";

        if (Validate::isLoadedObject($params['objOrder'])) {

            $this->__setConfigDefault();

            $this->context->smarty->assign(PACONF::PREFIX.'ORDER',TRUE);
            $this->context->smarty->assign(PACONF::PREFIX.'CART',FALSE);


            $smarty_ad=array();
            foreach ($params['objOrder']->getProductsDetail() as $value) {
                $smarty_ad[]=array(
                    'SKU'=>$this->parseProductSku($value['product_id'],(isset($value['product_attribute_id'])?$value['product_attribute_id']:FALSE),(isset($value['product_reference'])?$value['product_reference']:FALSE)),
                    'NAME'=>$value['product_name'],
                    'CATEGORY'=>$this->get_category_names_by_product($value['product_id'],FALSE),
                    'PRICE'=>$this->currencyConvertion(
                            array(
                                'price'=>(isset($value['total_price_tax_incl'])?floatval($value['total_price_tax_incl']):(isset($value['total_price_tax_incl'])?floatval($value['total_price_tax_incl']):0.00)),
                                'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                            )
                    ),
                    'QUANTITY'=>$value['product_quantity'],
                );
            }
            $this->context->smarty->assign(PACONF::PREFIX.'ORDER_PRODUCTS',$smarty_ad);
            if (isset($params['objOrder']->total_paid_tax_incl)&&isset($params['objOrder']->total_paid_tax_excl))
                $tax=$params['objOrder']->total_paid_tax_incl-$params['objOrder']->total_paid_tax_excl;
            else if (isset($params['objOrder']->total_products_wt)&&isset($params['objOrder']->total_products))
                $tax=$params['objOrder']->total_products_wt-$params['objOrder']->total_products;
            else
                $tax=0.00;
            $ORDER_DETAILS=array(
                'order_id'=>$params['objOrder']->id,
                'order_total'=>$this->currencyConvertion(
                        array(
                            'price'=>floatval(isset($params['objOrder']->total_paid_tax_incl)?$params['objOrder']->total_paid_tax_incl:(isset($params['objOrder']->total_paid)?$params['objOrder']->total_paid:0.00)),
                            'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                        )
                ),
                'order_sub_total'=>$this->currencyConvertion(
                        array(
                            'price'=>floatval($params['objOrder']->total_products_wt),
                            'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                        )
                ),
                'order_tax'=>$this->currencyConvertion(
                        array(
                            'price'=>floatval($tax),
                            'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                        )
                ),
                'order_shipping'=>$this->currencyConvertion(
                        array(
                            'price'=>floatval((isset($params['objOrder']->total_shipping_tax_incl)?$params['objOrder']->total_shipping_tax_incl:(isset($params['objOrder']->total_shipping)?$params['objOrder']->total_shipping:0.00))),
                            'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                        )
                ),
                'order_discount'=>$this->currencyConvertion(
                        array(
                            'price'=>(isset($params['objOrder']->total_discounts_tax_incl)?
                                    ($params['objOrder']->total_discounts_tax_incl>0?
                                            floatval($params['objOrder']->total_discounts_tax_incl):false):(isset($params['objOrder']->total_discounts)?
                                            ($params['objOrder']->total_discounts>0?
                                                    floatval($params['objOrder']->total_discounts):false):0.00)),
                            'conversion_rate'=>(isset($params['objOrder']->conversion_rate)?$params['objOrder']->conversion_rate:0.00),
                        )
                ),
            );
            $this->context->smarty->assign(PACONF::PREFIX.'ORDER_DETAILS',$ORDER_DETAILS);

            // avoid double tracking on complete order.
            self::$_isOrder=TRUE;
            return $this->display(__FILE__,'views/templates/hook/jstracking.tpl');
        }
    }

    public function hookFooter($params) {
        if ((int)$this->config->siteid<=0)
            return "";

        if (self::$_isOrder)
            return "";

        if (_PS_VERSION_<'1.5.6') {
            /* get page name the LAME way :) */
            if (method_exists($this->context->smarty,'get_template_vars')) { /* smarty_2 */
                $page_name=$this->context->smarty->get_template_vars('page_name');
            } else if (method_exists($this->context->smarty,'getTemplateVars')) {/* smarty */
                $page_name=$this->context->smarty->getTemplateVars('page_name');
            } else
                $page_name="";
        }
        $this->__setConfigDefault();
        $this->context->smarty->assign(PACONF::PREFIX.'ORDER',FALSE);

        /* cart tracking */
        if (!$this->context->cookie->PIWIKTrackCartFooter) {
            $this->context->cookie->PIWIKTrackCartFooter=time();
        }
        $date_upd=strtotime($this->context->cart->date_upd);
        if ($date_upd>=$this->context->cookie->PIWIKTrackCartFooter) {
            $this->context->cookie->PIWIKTrackCartFooter=$date_upd+2;
            $Currency=new Currency($this->context->cart->id_currency);
            $smarty_ad=$this->getCartProducts($Currency);
            if (count($smarty_ad)>0) {
                $this->context->smarty->assign(PACONF::PREFIX.'CART',TRUE);
                $this->context->smarty->assign(PACONF::PREFIX.'CART_PRODUCTS',$smarty_ad);
                $this->context->smarty->assign(PACONF::PREFIX.'CART_TOTAL',$this->currencyConvertion(
                                array(
                                    'price'=>$this->context->cart->getOrderTotal(),
                                    'conversion_rate'=>$Currency->conversion_rate,
                                )
                ));
            } else {
                $this->context->smarty->assign(PACONF::PREFIX.'CART',FALSE);
            }
            unset($smarty_ad,$Currency);
        } else {
            $this->context->smarty->assign(PACONF::PREFIX.'CART',FALSE);
        }
        $is404=false;
        if (!empty($this->context->controller->errors)) {
            foreach ($this->context->controller->errors as $key=> $value) {
                if ($value==Tools::displayError('Product not found'))
                    $is404=true;
                if ($value==Tools::displayError('This product is no longer available.'))
                    $is404=true;
            }
        }
        if (
                (strtolower(get_class($this->context->controller))=='pagenotfoundcontroller')||
                (isset($this->context->controller->php_self)&&($this->context->controller->php_self=='404'))||
                (isset($this->context->controller->page_name)&&(strtolower($this->context->controller->page_name)=='pagenotfound'))
        ) {
            $is404=true;
        }
        $this->context->smarty->assign(array("PK404"=>$is404));
        if (_PS_VERSION_<'1.5.6')
            $this->_hookFooterPS14($params,$page_name);
        else if (_PS_VERSION_>='1.5')
            $this->_hookFooter($params);
        return $this->display(__FILE__,'views/templates/hook/jstracking.tpl');
    }

    /**
     * add Prestashop !LATEST! specific settings
     * @param mixed $params
     * @since 0.4
     */
    private function _hookFooter($params) {
        /* product tracking */
        if (get_class($this->context->controller)=='ProductController') {
            $products=array(array('product'=>$this->context->controller->getProduct(),'categorys'=>NULL));
            if (isset($products)&&isset($products[0]['product'])) {
                $smarty_ad=array();
                foreach ($products as $product) {
                    if (!Validate::isLoadedObject($product['product']))
                        continue;
                    if ($product['categorys']==NULL)
                        $product['categorys']=$this->get_category_names_by_product($product['product']->id,FALSE);
                    $smarty_ad[]=array(
                        /* (required) SKU: Product unique identifier */
                        'SKU'=>$this->parseProductSku($product['product']->id,FALSE,(isset($product['product']->reference)?$product['product']->reference:FALSE)),
                        /* (optional) Product name */
                        'NAME'=>$product['product']->name,
                        /* (optional) Product category, or array of up to 5 categories */
                        'CATEGORY'=>$product['categorys'],//$category->name,
                        /* (optional) Product Price as displayed on the page */
                        'PRICE'=>$this->currencyConvertion(
                                array(
                                    'price'=>Product::getPriceStatic($product['product']->id,true,false),
                                    'conversion_rate'=>$this->context->currency->conversion_rate,
                                )
                        ),
                    );
                }
                $this->context->smarty->assign(array(PACONF::PREFIX.'PRODUCTS'=>$smarty_ad));
                unset($smarty_ad);
            }
        }

        /* category tracking */
        if (get_class($this->context->controller)=='CategoryController') {
            $category=$this->context->controller->getCategory();
            if (Validate::isLoadedObject($category)) {
                $this->context->smarty->assign(array(
                    PACONF::PREFIX.'category'=>array('NAME'=>$category->name),
                ));
            }
        }
    }

    /**
     * add Prestashop 1.4 to 1.5.6 specific settings
     * @param mixed $params
     * @since 0.4
     */
    private function _hookFooterPS14($params,$page_name) {
        if (empty($page_name)) {
            /* we can't do any thing use full  */
            return;
        }

        if (strtolower($page_name)=="product"&&isset($_GET['id_product'])&&Validate::isUnsignedInt($_GET['id_product'])) {
            $product=new Product($_GET['id_product'],false,(isset($_GET['id_lang'])&&Validate::isUnsignedInt($_GET['id_lang'])?$_GET['id_lang']:(isset($this->context->cookie->id_lang)?$this->context->cookie->id_lang:NULL)));
            if (!Validate::isLoadedObject($product))
                return;
            $product_categorys=$this->get_category_names_by_product($product->id,FALSE);
            $smarty_ad=array(
                array(
                    /* (required) SKU: Product unique identifier */
                    'SKU'=>$this->parseProductSku($product->id,FALSE,(isset($product->reference)?$product->reference:FALSE)),
                    /* (optional) Product name */
                    'NAME'=>$product->name,
                    /* (optional) Product category, or array of up to 5 categories */
                    'CATEGORY'=>$product_categorys,
                    /* (optional) Product Price as displayed on the page */
                    'PRICE'=>$this->currencyConvertion(
                            array(
                                'price'=>Product::getPriceStatic($product->id,true,false),
                                'conversion_rate'=>false,
                            )
                    ),
                )
            );
            $this->context->smarty->assign(array(PACONF::PREFIX.'PRODUCTS'=>$smarty_ad));
            unset($smarty_ad);
        }
        /* category tracking */
        if (strtolower($page_name)=="category"&&isset($_GET['id_category'])&&Validate::isUnsignedInt($_GET['id_category'])) {
            $category=new Category($_GET['id_category'],(isset($_GET['id_lang'])&&Validate::isUnsignedInt($_GET['id_lang'])?$_GET['id_lang']:(isset($this->context->cookie->id_lang)?$this->context->cookie->id_lang:NULL)));
            $this->context->smarty->assign(array(
                PACONF::PREFIX.'category'=>array('NAME'=>$category->name),
            ));
        }
    }

    /**
     * search action
     * @param array $params
     * @since 0.4
     */
    public function hookSearch($params) {
        if ((int)$this->config->siteid<=0)
            return "";
        $this->hookactionSearch($params);
    }

    /* HELPERS */

    public function displayWarning($error) {
        return'<div class="module_warning alert warning"><img src="'._PS_IMG_.'admin/warning.gif" alt="" title="" /> '.$error.'</div>';
    }
    
    /**
     * get products from cart
     * @return array
     */
    private function getCartProducts($currency= null) {
        $cart=array();
        if (!is_null($currency)||!Validate::isLoadedObject($currency))
            $currency=new Currency($this->context->cart->id_currency);
        foreach ($this->context->cart->getProducts() as $key=> $value) {
            if (isset($value['id_product'])||isset($value['name'])||isset($value['total_wt'])||isset($value['quantity'])) {
                $cart[]=array(
                    'SKU'=>$this->parseProductSku($value['id_product'],(isset($value['id_product_attribute'])&&$value['id_product_attribute']>0?$value['id_product_attribute']:FALSE),(isset($value['reference'])?$value['reference']:FALSE)),
                    'NAME'=>$value['name'].(isset($value['attributes'])?' ('.$value['attributes'].')':''),
                    'CATEGORY'=>$this->get_category_names_by_product($value['id_product'],FALSE),
                    'PRICE'=>$this->currencyConvertion(
                            array(
                                'price'=>$value['total_wt'],
                                'conversion_rate'=>$currency->conversion_rate,
                            )
                    ),
                    'QUANTITY'=>$value['quantity'],
                );
            }
        }
        return $cart;
    }

    private function __validateHelperProductId($v) {
        foreach ($this->config->validate_output as $key=> $value) {
            if ($key=="ID") {
                $this->_errors[]=$this->displayError(sprintf($this->l('Product id %s: missing variable {ID}'),"V{$v}"));
            } else if ($key=="ATTRID") {
                $this->_errors[]=$this->displayError(sprintf($this->l('Product id %s: missing variable {ATTRID}'),"V{$v}"));
            } else if ($key=="REFERENCE") {
                $this->_errors[]=$this->displayError(sprintf($this->l('Product id %s: missing variable {REFERENCE}'),"V{$v}"));
            }
        }
    }

    /**
     * get template for product id
     * @param int $v
     * @return string
     */
    private function getProductIdTemplate($v=1) {
        switch ($v) {
            case 1:
                $PIWIK_PRODID_V1=$this->config->PRODID_V1;
                return !empty($PIWIK_PRODID_V1)?$PIWIK_PRODID_V1:'{ID}-{ATTRID}#{REFERENCE}';
            case 2:
                $PIWIK_PRODID_V2=$this->config->PRODID_V2;
                return !empty($PIWIK_PRODID_V2)?$PIWIK_PRODID_V2:'{ID}#{REFERENCE}';
            case 3:
                $PIWIK_PRODID_V3=$this->config->PRODID_V3;
                return !empty($PIWIK_PRODID_V3)?$PIWIK_PRODID_V3:'{ID}-{ATTRID}';
        }
        return '{ID}';
    }

    /**
     * get timezone list
     * @return array
     */
    private function getTimezonesList($authtoken=null,$piwikhost=null) {
        $pktimezones=array();
        $tmp=PKHelper::getTimezonesList(false,$authtoken,$piwikhost);
        $this->displayErrorsPiwik();
        foreach ($tmp as $key=> $pktz) {
            if (!isset($pktimezones[$key])) {
                $pktimezones[$key]=array(
                    'name'=>$key,
                    'query'=>array(),
                );
            }
            foreach ($pktz as $pktzK=> $pktzV) {
                $pktimezones[$key]['query'][]=array(
                    'tzId'=>$pktzK,
                    'tzName'=>$pktzV,
                );
            }
        }
        unset($tmp,$pktz,$pktzV,$pktzK);
        return $pktimezones;
    }

    /**
     * get the correct template file to use
     * check for templates in current active shop theme falls back to default shipped with this module
     * @param string $file template.tpl
     * @param string $path relative path from root
     * @return string full path to the template file
     */
    private function _get_theme_file($file,$path="views/templates/admin") {
        $pk_templates_dir=dirname(__FILE__)."/".$path;
        $pk_templates_dir_theme=_PS_THEME_DIR_.'modules/'.$this->name."/".$path;
        if (file_exists($pk_templates_dir_theme."/".$file))
            return $pk_templates_dir_theme."/".$file;
        return $pk_templates_dir."/".$file;
    }

    /**
     * set css and javascript used within admin
     * @return void
     * @since 0.8.4
     */
    protected function setMedia() {
        $this->context->controller->addCss($this->_path.'css/styles.css');
        if (version_compare(_PS_VERSION_,'1.5.0.4',"<=")) {
            $this->context->controller->addJquery(_PS_JQUERY_VERSION_);
            $this->context->controller->addJs($this->_path.'js/jquery.alerts.js');
            $this->context->controller->addCss($this->_path.'js/jquery.alerts.css');
        }
        if (version_compare(_PS_VERSION_,'1.5.2.999',"<="))
            $this->context->controller->addJqueryPlugin('fancybox',_PS_JS_DIR_.'jquery/plugins/');
        if (version_compare(_PS_VERSION_,'1.6',"<"))
            $this->context->controller->addJqueryUI(array('ui.core','ui.widget'));
        if (version_compare(_PS_VERSION_,'1.5',">="))
            $this->context->controller->addJqueryPlugin('tagify',_PS_JS_DIR_.'jquery/plugins/');
    }

    /**
     * returns true if request is wizard
     * @return boolean
     * @since 0.8.4
     */
    private function isWizardRequest() {
        return Tools::getIsset('pkwizard');
    }

    private function parseProductSku($id,$attrid=FALSE,$ref=FALSE) {
        if (Validate::isInt($id)&&(!empty($attrid)&&!is_null($attrid)&&$attrid!==FALSE)&&(!empty($ref)&&!is_null($ref)&&$ref!==FALSE)) {
            $PIWIK_PRODID_V1=$this->config->PRODID_V1;
            return str_replace(array('{ID}','{ATTRID}','{REFERENCE}'),array($id,$attrid,$ref),$PIWIK_PRODID_V1);
        } elseif (Validate::isInt($id)&&(!empty($ref)&&!is_null($ref)&&$ref!==FALSE)) {
            $PIWIK_PRODID_V2=$this->config->PRODID_V2;
            return str_replace(array('{ID}','{REFERENCE}'),array($id,$ref),$PIWIK_PRODID_V2);
        } elseif (Validate::isInt($id)&&(!empty($attrid)&&!is_null($attrid)&&$attrid!==FALSE)) {
            $PIWIK_PRODID_V3=$this->config->PRODID_V3;
            return str_replace(array('{ID}','{ATTRID}'),array($id,$attrid),$PIWIK_PRODID_V3);
        } else {
            return $id;
        }
    }

    /** dumps all errors if any from wizard helper class through '$this->displayErrors' method, and sets 'PiwikWizardHelper::$errors' to empty  */
    public function displayErrorsPiwikWizard() {
        $this->displayErrors(PiwikWizardHelper::$errors);
        PiwikWizardHelper::$errors=array();
    }

    /** dumps all errors if any from piwik helper class through '$this->displayErrors' method, and sets 'PKHelper::$error(s)' to empty  */
    public function displayErrorsPiwik() {
        $this->displayErrors(PKHelper::$errors);
        PKHelper::$errors=PKHelper::$error="";
    }

    /**
     * Makes a call to '$this->displayError' for each error contained within the array and puts the returned values into variable '$this->_errors' as an array
     * @param array|object $errors if object a call to method 'getErrors()' is made followed by a call to 'clearErrors()' if the method exists
     * @return void
     * @changed in version '0.8.4' to allow the use of object methods getErrors() and clearErrors(), this is to minimize the need to collect errors from objects into new single variables
     */
    public function displayErrors($errors) {
        $_errors=array();
        if (!empty($errors)&&is_array($errors)) {
            $_errors=$errors;
        } else if (is_object($errors)&&method_exists($errors,'getErrors')) {
            $_errors=$errors->getErrors();
            if (method_exists($errors,'clearErrors')) {
                $errors->clearErrors();
            }
        }
        foreach ($_errors as $key=> $value) {
            $this->_errors[]=$this->displayError($value);
        }
    }

    /**
     * convert into default currency used in Piwik
     * @param array $params
     * @return float
     * @since 0.4
     */
    private function currencyConvertion($params) {
        $pkc=$this->config->DEFAULT_CURRENCY;
        if (empty($pkc))
            return (float)$params['price'];
        if ($params['conversion_rate']===FALSE||$params['conversion_rate']==0.00||$params['conversion_rate']==1.00) {
            // shop default
            return Tools::convertPrice((float)$params['price'],Currency::getCurrencyInstance((int)(Currency::getIdByIsoCode($pkc))));
        } else {
            $_shop_price=(float)((float)$params['price']/(float)$params['conversion_rate']);
            return Tools::convertPrice($_shop_price,Currency::getCurrencyInstance((int)(Currency::getIdByIsoCode($pkc))));
        }
        return (float)$params['price'];
    }

    /**
     * get category names by product id
     * @param integer $id product id
     * @param boolean $array get categories as PHP array (TRUE), or javascript (FALSE)
     * @return string|array
     */
    private function get_category_names_by_product($id,$array=true) {
        $_categories=Product::getProductCategoriesFull($id,$this->context->cookie->id_lang);
        if (!is_array($_categories)) {
            if ($array)
                return array();
            else
                return "[]";
        }
        if ($array) {
            $categories=array();
            foreach ($_categories as $category) {
                $categories[]=$category['name'];
                if (count($categories)==5)
                    break;
            }
        } else {
            $categories='[';
            $c=0;
            foreach ($_categories as $category) {
                $c++;
                $categories .= '"'.addcslashes($category['name'],'"').'",';
                if ($c==5)
                    break;
            }
            $categories=rtrim($categories,',').']';
        }
        return $categories;
    }

    /**
     * get module link
     * @param string $module
     * @param string $controller
     * @return string
     * @since 0.4
     */
    public static function getModuleLink($module,$controller='default') {
        if (version_compare(_PS_VERSION_,'1.5.0.13',"<="))
            return Tools::getShopDomainSsl(true,true)._MODULE_DIR_.$module.'/'.$controller.'.php';
        else
            return Context::getContext()->link->getModuleLink($module,$controller);
    }

    private function __setConfigDefault() {
        $key_prefix=PACONF::PREFIX;
        $keys=array(
            $key_prefix.'EXHTML',$key_prefix.'DHASHTAG',
            $key_prefix.'SET_DOMAINS',$key_prefix.'COOKIE_DOMAIN',
            $key_prefix.'DNT',$key_prefix.'SESSION_TIMEOUT',
            $key_prefix.'RCOOKIE_TIMEOUT',$key_prefix.'COOKIE_TIMEOUT',
            $key_prefix.'SITEID',$key_prefix.'USE_PROXY',
            $key_prefix.'HOST',$key_prefix.'PROXY_SCRIPT',
            $key_prefix.'LINKTRACK',$key_prefix.'LINKCLS',
            $key_prefix.'LINKTTIME',$key_prefix.'COOKIEPREFIX',
            $key_prefix.'COOKIEPATH',$key_prefix.'LINKCLSIGNORE',
            $key_prefix.'APTURL',
        );
        $configuration=Configuration::getMultiple($keys);

        $this->context->smarty->assign($key_prefix.'EXHTML',$configuration["{$key_prefix}EXHTML"]);
        $this->context->smarty->assign($key_prefix.'COOKIE_DOMAIN',(empty($configuration["{$key_prefix}COOKIE_DOMAIN"])?FALSE:$configuration["{$key_prefix}COOKIE_DOMAIN"]));
        $this->context->smarty->assign($key_prefix.'COOKIEPREFIX',(empty($configuration["{$key_prefix}COOKIEPREFIX"])?FALSE:$configuration["{$key_prefix}COOKIEPREFIX"]));
        $this->context->smarty->assign($key_prefix.'COOKIEPATH',(empty($configuration["{$key_prefix}COOKIEPATH"])?FALSE:$configuration["{$key_prefix}COOKIEPATH"]));
        $this->context->smarty->assign($key_prefix.'SITEID',$configuration["{$key_prefix}SITEID"]);
        $this->context->smarty->assign($key_prefix.'VER',$this->piwikVersion);
        $this->context->smarty->assign($key_prefix.'USE_PROXY',(bool)$configuration["{$key_prefix}USE_PROXY"]);
        $this->context->smarty->assign($key_prefix.'DHASHTAG',(bool)$configuration[$key_prefix.'DHASHTAG']);
        $this->context->smarty->assign($key_prefix.'APTURL',(bool)$configuration[$key_prefix.'APTURL']);
        $this->context->smarty->assign($key_prefix.'HOSTAPI',$configuration["{$key_prefix}HOST"]);
        $this->context->smarty->assign($key_prefix.'LINKTRACK',(bool)$configuration[$key_prefix.'LINKTRACK']);
        $this->context->smarty->assign($key_prefix.'DNT',(bool)$configuration["{$key_prefix}DNT"]);

        // using proxy script?
        if ((bool)$configuration["{$key_prefix}USE_PROXY"])
            $this->context->smarty->assign($key_prefix.'HOST',$configuration["{$key_prefix}PROXY_SCRIPT"]);
        else
            $this->context->smarty->assign($key_prefix.'HOST',$configuration["{$key_prefix}HOST"]);

        // timeout
        $pkvct=(int)$configuration["{$key_prefix}COOKIE_TIMEOUT"];
        if ($pkvct!=0&&$pkvct!==FALSE&&($pkvct!=(int)(self::PK_VC_TIMEOUT*60))) {
            $this->context->smarty->assign($key_prefix.'COOKIE_TIMEOUT',$pkvct);
        }
        unset($pkvct);
        $pkrct=(int)$configuration["{$key_prefix}RCOOKIE_TIMEOUT"];
        if ($pkrct!=0&&$pkrct!==FALSE&&($pkrct!=(int)(self::PK_RC_TIMEOUT*60))) {
            $this->context->smarty->assign($key_prefix.'RCOOKIE_TIMEOUT',$pkrct);
        }
        unset($pkrct);
        $pksct=(int)$configuration["{$key_prefix}SESSION_TIMEOUT"];
        if ($pksct!=0&&$pksct!==FALSE&&($pksct!=(int)(self::PK_SC_TIMEOUT*60))) {
            $this->context->smarty->assign($key_prefix.'SESSION_TIMEOUT',$pksct);
        }
        unset($pksct);
        // domains
        if (!empty($configuration["{$key_prefix}SET_DOMAINS"])) {
            $sdArr=explode(',',$configuration["{$key_prefix}SET_DOMAINS"]);
            if (count($sdArr)>1)
                $PIWIK_SET_DOMAINS="['".trim(implode("','",$sdArr),",'")."']";
            else
                $PIWIK_SET_DOMAINS="'{$sdArr[0]}'";
            $this->context->smarty->assign($key_prefix.'SET_DOMAINS',(!empty($PIWIK_SET_DOMAINS)?$PIWIK_SET_DOMAINS:FALSE));
            unset($sdArr);
        }else {
            $this->context->smarty->assign($key_prefix.'SET_DOMAINS',FALSE);
        }
        unset($PIWIK_SET_DOMAINS);
        // link classes
        if (!empty($configuration["{$key_prefix}LINKCLS"])) {
            $sdArr=explode(',',$configuration["{$key_prefix}LINKCLS"]);
            if (count($sdArr)>1)
                $PIWIK_LINKClS="['".trim(implode("','",$sdArr),",'")."']";
            else
                $PIWIK_LINKClS="['{$sdArr[0]}']";
            $this->context->smarty->assign($key_prefix.'LINKCLS',(!empty($PIWIK_LINKClS)?$PIWIK_LINKClS:FALSE));
            unset($sdArr);
        }else {
            $this->context->smarty->assign($key_prefix.'LINKCLS',FALSE);
        }
        unset($PIWIK_LINKClS);
        // link ignore classes
        if (!empty($configuration["{$key_prefix}LINKCLSIGNORE"])) {
            $sdArr=explode(',',$configuration["{$key_prefix}LINKCLSIGNORE"]);
            if (count($sdArr)>1)
                $PIWIK_LINKClSIGNORE="['".trim(implode("','",$sdArr),",'")."']";
            else
                $PIWIK_LINKClSIGNORE="['{$sdArr[0]}']";
            $this->context->smarty->assign($key_prefix.'LINKCLSIGNORE',(!empty($PIWIK_LINKClSIGNORE)?$PIWIK_LINKClSIGNORE:FALSE));
            unset($sdArr);
        }else {
            $this->context->smarty->assign($key_prefix.'LINKCLSIGNORE',FALSE);
        }
        unset($PIWIK_LINKClSIGNORE);
        // link track time
        $tmp=$configuration["{$key_prefix}LINKTTIME"];
        if ($tmp!=0&&$tmp!==FALSE) {
            $this->context->smarty->assign($key_prefix.'LINKTTIME',(int)$tmp);
        }

        if (version_compare(_PS_VERSION_,'1.5','<')&&$this->context->cookie->isLogged()) {
            $this->context->smarty->assign($key_prefix.'UUID',$this->context->cookie->id_customer);
        } else if ($this->context->customer->isLogged()) {
            $this->context->smarty->assign($key_prefix.'UUID',$this->context->customer->id);
        }
    }

    /* INSTALL / UNINSTALL */

    /** Reset module configuration */
    public function reset() {
        foreach ($this->config->getAll() as $key=> $value) {
            if (Shop::getContext()==Shop::CONTEXT_ALL) {
                // delete for all shops.!
                Configuration::deleteByName($key);
            } else {
                // delete only for current shop.!
                if (!Validate::isConfigName($key))
                    continue;
                if (method_exists('Shop','getContextShopID'))
                    $id_shop=Shop::getContextShopID(true);
                else
                    $id_shop=$this->context->shop->id;
                Db::getInstance()->execute('
                    DELETE FROM `'._DB_PREFIX_.'configuration_lang`
                    WHERE `id_configuration` IN (
                            SELECT `id_configuration`
                            FROM `'._DB_PREFIX_.'configuration`
                            WHERE `name` = "'.pSQL($key).'" AND `id_shop` = "'.pSQL($id_shop).'"
                    )');
                Db::getInstance()->execute('
                    DELETE FROM `'._DB_PREFIX_.'configuration`
                    WHERE `name` = "'.pSQL($key).'" AND `id_shop` = "'.pSQL($id_shop).'"');
            }
        }
        return true;
    }

    /**
     * Install the module
     * @return boolean false on install error
     */
    public function install() {
        /* create complete new page tab */
        $tab=new Tab();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int)$lang['id_lang']]='Piwik Analytics';
        }
        $tab->module='piwikanalyticsjs';
        $tab->active=TRUE;
        if (version_compare(_PS_VERSION_,'1.5.0.5',">=")&&version_compare(_PS_VERSION_,'1.5.3.999',"<=")) {
            $tab->class_name='PiwikAnalytics15';
        } else if (version_compare(_PS_VERSION_,'1.5.0.13',"<=")) {
            $tab->class_name='AdminPiwikAnalytics';
        } else {
            $tab->class_name='PiwikAnalytics';
        }

        if (method_exists('Tab','getInstanceFromClassName')) {
            $AdminParentStats=Tab::getInstanceFromClassName('AdminStats');
            if ($AdminParentStats==null||!Validate::isLoadedObject($AdminParentStats)||(intval($AdminParentStats->id)<=0))
                $AdminParentStats=Tab::getInstanceFromClassName('AdminParentStats');
        } else if (method_exists('Tab','getIdFromClassName')) {
            $tmpId=Tab::getIdFromClassName('AdminStats');
            if ($tmpId!=null&&$tmpId>0)
                $AdminParentStats=new Tab($tmpId);
            else {
                $tmpId=Tab::getIdFromClassName('AdminParentStats');
                if ($tmpId!=null&&$tmpId>0)
                    $AdminParentStats=new Tab($tmpId);
            }
        }

        $tab->id_parent=(int)(isset($AdminParentStats)&&intval($AdminParentStats->id)>0?$AdminParentStats->id:-1);
        if (!$tab->add()) {
            $this->_errors[]=sprintf($this->l('Unable to create new tab "Piwik Analytics", Please forward the following info to the developer %s'),"<br/>"
                    .(isset($AdminParentStats)?"\$AdminParentStats: True":"\$AdminParentStats:: False")
                    ."<br/>Type of \$AdminParentStats: ".gettype($AdminParentStats)
                    ."<br/>Class name of \$AdminParentStats: ".get_class($AdminParentStats)
                    ."<br/>Prestashop version: "._PS_VERSION_
                    ."<br/>PHP version: ".PHP_VERSION
            );
        }

        /* default values */
        foreach ($this->config->getAll() as $key => $value) {
            $this->config->update($key,$value);
        }

//  properly not needed only here as a reminder, 
//  if management of carts in Piwik becomes available
//        if (!Db::getInstance()->Execute('
//			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'piwikanalytics` (
//				`id_pk_analytics` int(11) NOT NULL AUTO_INCREMENT,
//				`id_order` int(11) NOT NULL,
//				`id_customer` int(10) NOT NULL,
//				`id_shop` int(11) NOT NULL,
//				`sent` tinyint(1) DEFAULT NULL,
//				`date_add` datetime DEFAULT NULL,
//				PRIMARY KEY (`id_pk_analytics`),
//				KEY `id_order` (`id_order`),
//				KEY `sent` (`sent`)
//			) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1'))

        $ret=parent::install();
        if ($ret) {
            foreach ($this->config->getHooks() as $value) {
                $this->registerHook($value);
            }
        }
        return $ret;
    }

    /**
     * Uninstall the module
     * @return boolean false on uninstall error
     */
    public function uninstall() {
        if (parent::uninstall()) {
            foreach ($this->config->getAll() as $key=> $value)
                Configuration::deleteByName($key);
            try {
                if (method_exists('Tab','getInstanceFromClassName')) {
                    $AdminParentStats=Tab::getInstanceFromClassName('PiwikAnalytics15');
                    if (!isset($AdminParentStats)||!Validate::isLoadedObject($AdminParentStats))
                        $AdminParentStats=Tab::getInstanceFromClassName('AdminPiwikAnalytics');
                    if (!isset($AdminParentStats)||!Validate::isLoadedObject($AdminParentStats))
                        $AdminParentStats=Tab::getInstanceFromClassName('PiwikAnalytics');
                } else if (method_exists('Tab','getIdFromClassName')) {
                    $tmpId=Tab::getIdFromClassName('PiwikAnalytics15');
                    if (!isset($tmpId)||!((bool)$tmpId)||((int)$tmpId<1))
                        $tmpId=Tab::getIdFromClassName('AdminPiwikAnalytics');
                    if (!isset($tmpId)||!((bool)$tmpId)||((int)$tmpId<1))
                        $tmpId=Tab::getIdFromClassName('PiwikAnalytics');
                    if (!isset($tmpId)||!((bool)$tmpId)||((int)$tmpId<1))
                        $AdminParentStats=new Tab($tmpId);
                }
                if (isset($AdminParentStats)&&Validate::isLoadedObject($AdminParentStats)) {
                    $AdminParentStats->delete();
                }
            } catch (Exception $ex) {
                
            }
            return true;
        }
        return false;
    }

}
