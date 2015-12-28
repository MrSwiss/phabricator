<?php


/**
 * @task fields Managing Fields
 * @task text Display Text
 * @task config Edit Engine Configuration
 * @task uri Managing URIs
 * @task load Creating and Loading Objects
 * @task web Responding to Web Requests
 * @task edit Responding to Edit Requests
 * @task http Responding to HTTP Parameter Requests
 * @task conduit Responding to Conduit Requests
 */
abstract class PhabricatorEditEngine
  extends Phobject
  implements PhabricatorPolicyInterface {

  const EDITENGINECONFIG_DEFAULT = 'default';

  private $viewer;
  private $controller;
  private $isCreate;
  private $editEngineConfiguration;
  private $contextParameters = array();

  final public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  final public function getViewer() {
    return $this->viewer;
  }

  final public function setController(PhabricatorController $controller) {
    $this->controller = $controller;
    $this->setViewer($controller->getViewer());
    return $this;
  }

  final public function getController() {
    return $this->controller;
  }

  final public function getEngineKey() {
    return $this->getPhobjectClassConstant('ENGINECONST', 64);
  }

  final public function getApplication() {
    $app_class = $this->getEngineApplicationClass();
    return PhabricatorApplication::getByClass($app_class);
  }

  final public function addContextParameter($key) {
    $this->contextParameters[] = $key;
    return $this;
  }


/* -(  Managing Fields  )---------------------------------------------------- */


  abstract public function getEngineApplicationClass();
  abstract protected function buildCustomEditFields($object);

  protected function didBuildCustomEditFields($object, array $fields) {
    return;
  }

  public function getFieldsForConfig(
    PhabricatorEditEngineConfiguration $config) {

    $object = $this->newEditableObject();

    $this->editEngineConfiguration = $config;

    // This is mostly making sure that we fill in default values.
    $this->setIsCreate(true);

    return $this->buildEditFields($object);
  }

  final protected function buildEditFields($object) {
    $viewer = $this->getViewer();

    $fields = $this->buildCustomEditFields($object);

    foreach ($fields as $field) {
      $field
        ->setViewer($viewer)
        ->setObject($object);
    }

    $fields = mpull($fields, null, 'getKey');
    $this->didBuildCustomEditFields($object, $fields);

    $extensions = PhabricatorEditEngineExtension::getAllEnabledExtensions();
    foreach ($extensions as $extension) {
      $extension->setViewer($viewer);

      if (!$extension->supportsObject($this, $object)) {
        continue;
      }

      $extension_fields = $extension->buildCustomEditFields($this, $object);

      // TODO: Validate this in more detail with a more tailored error.
      assert_instances_of($extension_fields, 'PhabricatorEditField');

      foreach ($extension_fields as $field) {
        $field
          ->setViewer($viewer)
          ->setObject($object);
      }

      $extension_fields = mpull($extension_fields, null, 'getKey');
      $extension->didBuildCustomEditFields($this, $object, $extension_fields);

      foreach ($extension_fields as $key => $field) {
        $fields[$key] = $field;
      }
    }

    $config = $this->getEditEngineConfiguration();
    $fields = $config->applyConfigurationToFields($this, $object, $fields);

    return $fields;
  }


/* -(  Display Text  )------------------------------------------------------- */


  /**
   * @task text
   */
  abstract public function getEngineName();


  /**
   * @task text
   */
  abstract protected function getObjectCreateTitleText($object);

  /**
   * @task text
   */
  protected function getFormHeaderText($object) {
    $config = $this->getEditEngineConfiguration();
    return $config->getName();
  }

  /**
   * @task text
   */
  abstract protected function getObjectEditTitleText($object);


  /**
   * @task text
   */
  abstract protected function getObjectCreateShortText();


  /**
   * @task text
   */
  abstract protected function getObjectEditShortText($object);


  /**
   * @task text
   */
  protected function getObjectCreateButtonText($object) {
    return $this->getObjectCreateTitleText($object);
  }


  /**
   * @task text
   */
  protected function getObjectEditButtonText($object) {
    return pht('Save Changes');
  }


  /**
   * @task text
   */
  protected function getCommentViewSeriousHeaderText($object) {
    return pht('Take Action');
  }


  /**
   * @task text
   */
  protected function getCommentViewSeriousButtonText($object) {
    return pht('Submit');
  }


  /**
   * @task text
   */
  protected function getCommentViewHeaderText($object) {
    return $this->getCommentViewSeriousHeaderText($object);
  }


  /**
   * @task text
   */
  protected function getCommentViewButtonText($object) {
    return $this->getCommentViewSeriousButtonText($object);
  }


  /**
   * @task text
   */
  protected function getQuickCreateMenuHeaderText() {
    return $this->getObjectCreateShortText();
  }


  /**
   * Return a human-readable header describing what this engine is used to do,
   * like "Configure Maniphest Task Forms".
   *
   * @return string Human-readable description of the engine.
   * @task text
   */
  abstract public function getSummaryHeader();


  /**
   * Return a human-readable summary of what this engine is used to do.
   *
   * @return string Human-readable description of the engine.
   * @task text
   */
  abstract public function getSummaryText();




/* -(  Edit Engine Configuration  )------------------------------------------ */


  protected function supportsEditEngineConfiguration() {
    return true;
  }

  final protected function getEditEngineConfiguration() {
    return $this->editEngineConfiguration;
  }

  private function newConfigurationQuery() {
    return id(new PhabricatorEditEngineConfigurationQuery())
      ->setViewer($this->getViewer())
      ->withEngineKeys(array($this->getEngineKey()));
  }

  private function loadEditEngineConfigurationWithQuery(
    PhabricatorEditEngineConfigurationQuery $query,
    $sort_method) {

    if ($sort_method) {
      $results = $query->execute();
      $results = msort($results, $sort_method);
      $result = head($results);
    } else {
      $result = $query->executeOne();
    }

    if (!$result) {
      return null;
    }

    $this->editEngineConfiguration = $result;
    return $result;
  }

  private function loadEditEngineConfigurationWithIdentifier($identifier) {
    $query = $this->newConfigurationQuery()
      ->withIdentifiers(array($identifier));

    return $this->loadEditEngineConfigurationWithQuery($query, null);
  }

  private function loadDefaultConfiguration() {
    $query = $this->newConfigurationQuery()
      ->withIdentifiers(
        array(
          self::EDITENGINECONFIG_DEFAULT,
        ))
      ->withIgnoreDatabaseConfigurations(true);

    return $this->loadEditEngineConfigurationWithQuery($query, null);
  }

  private function loadDefaultCreateConfiguration() {
    $query = $this->newConfigurationQuery()
      ->withIsDefault(true)
      ->withIsDisabled(false);

    return $this->loadEditEngineConfigurationWithQuery(
      $query,
      'getCreateSortKey');
  }

  public function loadDefaultEditConfiguration() {
    $query = $this->newConfigurationQuery()
      ->withIsEdit(true)
      ->withIsDisabled(false);

    return $this->loadEditEngineConfigurationWithQuery(
      $query,
      'getEditSortKey');
  }

  final public function getBuiltinEngineConfigurations() {
    $configurations = $this->newBuiltinEngineConfigurations();

    if (!$configurations) {
      throw new Exception(
        pht(
          'EditEngine ("%s") returned no builtin engine configurations, but '.
          'an edit engine must have at least one configuration.',
          get_class($this)));
    }

    assert_instances_of($configurations, 'PhabricatorEditEngineConfiguration');

    $has_default = false;
    foreach ($configurations as $config) {
      if ($config->getBuiltinKey() == self::EDITENGINECONFIG_DEFAULT) {
        $has_default = true;
      }
    }

    if (!$has_default) {
      $first = head($configurations);
      if (!$first->getBuiltinKey()) {
        $first
          ->setBuiltinKey(self::EDITENGINECONFIG_DEFAULT)
          ->setIsDefault(true)
          ->setIsEdit(true);

        if (!strlen($first->getName())) {
          $first->setName($this->getObjectCreateShortText());
        }
    } else {
        throw new Exception(
          pht(
            'EditEngine ("%s") returned builtin engine configurations, '.
            'but none are marked as default and the first configuration has '.
            'a different builtin key already. Mark a builtin as default or '.
            'omit the key from the first configuration',
            get_class($this)));
      }
    }

    $builtins = array();
    foreach ($configurations as $key => $config) {
      $builtin_key = $config->getBuiltinKey();

      if ($builtin_key === null) {
        throw new Exception(
          pht(
            'EditEngine ("%s") returned builtin engine configurations, '.
            'but one (with key "%s") is missing a builtin key. Provide a '.
            'builtin key for each configuration (you can omit it from the '.
            'first configuration in the list to automatically assign the '.
            'default key).',
            get_class($this),
            $key));
      }

      if (isset($builtins[$builtin_key])) {
        throw new Exception(
          pht(
            'EditEngine ("%s") returned builtin engine configurations, '.
            'but at least two specify the same builtin key ("%s"). Engines '.
            'must have unique builtin keys.',
            get_class($this),
            $builtin_key));
      }

      $builtins[$builtin_key] = $config;
    }


    return $builtins;
  }

  protected function newBuiltinEngineConfigurations() {
    return array(
      $this->newConfiguration(),
    );
  }

  final protected function newConfiguration() {
    return PhabricatorEditEngineConfiguration::initializeNewConfiguration(
      $this->getViewer(),
      $this);
  }


/* -(  Managing URIs  )------------------------------------------------------ */


  /**
   * @task uri
   */
  abstract protected function getObjectViewURI($object);


  /**
   * @task uri
   */
  protected function getObjectCreateCancelURI($object) {
    return $this->getApplication()->getApplicationURI();
  }


  /**
   * @task uri
   */
  protected function getEditorURI() {
    return $this->getApplication()->getApplicationURI('edit/');
  }


  /**
   * @task uri
   */
  protected function getObjectEditCancelURI($object) {
    return $this->getObjectViewURI($object);
  }


  /**
   * @task uri
   */
  public function getEditURI($object = null, $path = null) {
    $parts = array();

    $parts[] = $this->getEditorURI();

    if ($object && $object->getID()) {
      $parts[] = $object->getID().'/';
    }

    if ($path !== null) {
      $parts[] = $path;
    }

    return implode('', $parts);
  }


/* -(  Creating and Loading Objects  )--------------------------------------- */


  /**
   * Initialize a new object for creation.
   *
   * @return object Newly initialized object.
   * @task load
   */
  abstract protected function newEditableObject();


  /**
   * Build an empty query for objects.
   *
   * @return PhabricatorPolicyAwareQuery Query.
   * @task load
   */
  abstract protected function newObjectQuery();


  /**
   * Test if this workflow is creating a new object or editing an existing one.
   *
   * @return bool True if a new object is being created.
   * @task load
   */
  final public function getIsCreate() {
    return $this->isCreate;
  }


  /**
   * Flag this workflow as a create or edit.
   *
   * @param bool True if this is a create workflow.
   * @return this
   * @task load
   */
  private function setIsCreate($is_create) {
    $this->isCreate = $is_create;
    return $this;
  }


  /**
   * Try to load an object by ID, PHID, or monogram. This is done primarily
   * to make Conduit a little easier to use.
   *
   * @param wild ID, PHID, or monogram.
   * @param list<const> List of required capability constants, or omit for
   *   defaults.
   * @return object Corresponding editable object.
   * @task load
   */
  private function newObjectFromIdentifier(
    $identifier,
    array $capabilities = array()) {
    if (is_int($identifier) || ctype_digit($identifier)) {
      $object = $this->newObjectFromID($identifier, $capabilities);

      if (!$object) {
        throw new Exception(
          pht(
            'No object exists with ID "%s".',
            $identifier));
      }

      return $object;
    }

    $type_unknown = PhabricatorPHIDConstants::PHID_TYPE_UNKNOWN;
    if (phid_get_type($identifier) != $type_unknown) {
      $object = $this->newObjectFromPHID($identifier, $capabilities);

      if (!$object) {
        throw new Exception(
          pht(
            'No object exists with PHID "%s".',
            $identifier));
      }

      return $object;
    }

    $target = id(new PhabricatorObjectQuery())
      ->setViewer($this->getViewer())
      ->withNames(array($identifier))
      ->executeOne();
    if (!$target) {
      throw new Exception(
        pht(
          'Monogram "%s" does not identify a valid object.',
          $identifier));
    }

    $expect = $this->newEditableObject();
    $expect_class = get_class($expect);
    $target_class = get_class($target);
    if ($expect_class !== $target_class) {
      throw new Exception(
        pht(
          'Monogram "%s" identifies an object of the wrong type. Loaded '.
          'object has class "%s", but this editor operates on objects of '.
          'type "%s".',
          $identifier,
          $target_class,
          $expect_class));
    }

    // Load the object by PHID using this engine's standard query. This makes
    // sure it's really valid, goes through standard policy check logic, and
    // picks up any `need...()` clauses we want it to load with.

    $object = $this->newObjectFromPHID($target->getPHID(), $capabilities);
    if (!$object) {
      throw new Exception(
        pht(
          'Failed to reload object identified by monogram "%s" when '.
          'querying by PHID.',
          $identifier));
    }

    return $object;
  }

  /**
   * Load an object by ID.
   *
   * @param int Object ID.
   * @param list<const> List of required capability constants, or omit for
   *   defaults.
   * @return object|null Object, or null if no such object exists.
   * @task load
   */
  private function newObjectFromID($id, array $capabilities = array()) {
    $query = $this->newObjectQuery()
      ->withIDs(array($id));

    return $this->newObjectFromQuery($query, $capabilities);
  }


  /**
   * Load an object by PHID.
   *
   * @param phid Object PHID.
   * @param list<const> List of required capability constants, or omit for
   *   defaults.
   * @return object|null Object, or null if no such object exists.
   * @task load
   */
  private function newObjectFromPHID($phid, array $capabilities = array()) {
    $query = $this->newObjectQuery()
      ->withPHIDs(array($phid));

    return $this->newObjectFromQuery($query, $capabilities);
  }


  /**
   * Load an object given a configured query.
   *
   * @param PhabricatorPolicyAwareQuery Configured query.
   * @param list<const> List of required capabilitiy constants, or omit for
   *  defaults.
   * @return object|null Object, or null if no such object exists.
   * @task load
   */
  private function newObjectFromQuery(
    PhabricatorPolicyAwareQuery $query,
    array $capabilities = array()) {

    $viewer = $this->getViewer();

    if (!$capabilities) {
      $capabilities = array(
        PhabricatorPolicyCapability::CAN_VIEW,
        PhabricatorPolicyCapability::CAN_EDIT,
      );
    }

    $object = $query
      ->setViewer($viewer)
      ->requireCapabilities($capabilities)
      ->executeOne();
    if (!$object) {
      return null;
    }

    return $object;
  }


  /**
   * Verify that an object is appropriate for editing.
   *
   * @param wild Loaded value.
   * @return void
   * @task load
   */
  private function validateObject($object) {
    if (!$object || !is_object($object)) {
      throw new Exception(
        pht(
          'EditEngine "%s" created or loaded an invalid object: object must '.
          'actually be an object, but is of some other type ("%s").',
          get_class($this),
          gettype($object)));
    }

    if (!($object instanceof PhabricatorApplicationTransactionInterface)) {
      throw new Exception(
        pht(
          'EditEngine "%s" created or loaded an invalid object: object (of '.
          'class "%s") must implement "%s", but does not.',
          get_class($this),
          get_class($object),
          'PhabricatorApplicationTransactionInterface'));
    }
  }


/* -(  Responding to Web Requests  )----------------------------------------- */


  final public function buildResponse() {
    $viewer = $this->getViewer();
    $controller = $this->getController();
    $request = $controller->getRequest();

    $action = $request->getURIData('editAction');

    $capabilities = array();
    $use_default = false;
    $require_create = true;
    switch ($action) {
      case 'comment':
        $capabilities = array(
          PhabricatorPolicyCapability::CAN_VIEW,
        );
        $use_default = true;
        break;
      case 'parameters':
        $use_default = true;
        break;
      case 'nodefault':
      case 'nocreate':
      case 'nomanage':
        $require_create = false;
        break;
      default:
        break;
    }

    $id = $request->getURIData('id');

    if ($id) {
      $this->setIsCreate(false);
      $object = $this->newObjectFromID($id, $capabilities);
      if (!$object) {
        return new Aphront404Response();
      }
    } else {
      // Make sure the viewer has permission to create new objects of
      // this type if we're going to create a new object.
      if ($require_create) {
        $this->requireCreateCapability();
      }

      $this->setIsCreate(true);
      $object = $this->newEditableObject();
    }

    $this->validateObject($object);

    if ($use_default) {
      $config = $this->loadDefaultConfiguration();
      if (!$config) {
        return new Aphront404Response();
      }
    } else {
      $form_key = $request->getURIData('formKey');
      if (strlen($form_key)) {
        $config = $this->loadEditEngineConfigurationWithIdentifier($form_key);

        if (!$config) {
          return new Aphront404Response();
        }

        if ($id && !$config->getIsEdit()) {
          return $this->buildNotEditFormRespose($object, $config);
        }
      } else {
        if ($id) {
          $config = $this->loadDefaultEditConfiguration();
          if (!$config) {
            return $this->buildNoEditResponse($object);
          }
        } else {
          $config = $this->loadDefaultCreateConfiguration();
          if (!$config) {
            return $this->buildNoCreateResponse($object);
          }
        }
      }
    }

    if ($config->getIsDisabled()) {
      return $this->buildDisabledFormResponse($object, $config);
    }

    switch ($action) {
      case 'parameters':
        return $this->buildParametersResponse($object);
      case 'nodefault':
        return $this->buildNoDefaultResponse($object);
      case 'nocreate':
        return $this->buildNoCreateResponse($object);
      case 'nomanage':
        return $this->buildNoManageResponse($object);
      case 'comment':
        return $this->buildCommentResponse($object);
      default:
        return $this->buildEditResponse($object);
    }
  }

  private function buildCrumbs($object, $final = false) {
    $controller = $this->getcontroller();

    $crumbs = $controller->buildApplicationCrumbsForEditEngine();
    if ($this->getIsCreate()) {
      $create_text = $this->getObjectCreateShortText();
      if ($final) {
        $crumbs->addTextCrumb($create_text);
      } else {
        $edit_uri = $this->getEditURI($object);
        $crumbs->addTextCrumb($create_text, $edit_uri);
      }
    } else {
      $crumbs->addTextCrumb(
        $this->getObjectEditShortText($object),
        $this->getObjectViewURI($object));

      $edit_text = pht('Edit');
      if ($final) {
        $crumbs->addTextCrumb($edit_text);
      } else {
        $edit_uri = $this->getEditURI($object);
        $crumbs->addTextCrumb($edit_text, $edit_uri);
      }
    }

    return $crumbs;
  }

  private function buildEditResponse($object) {
    $viewer = $this->getViewer();
    $controller = $this->getController();
    $request = $controller->getRequest();

    $fields = $this->buildEditFields($object);
    $template = $object->getApplicationTransactionTemplate();

    $validation_exception = null;
    if ($request->isFormPost()) {
      $submit_fields = $fields;

      foreach ($submit_fields as $key => $field) {
        if (!$field->shouldGenerateTransactionsFromSubmit()) {
          unset($submit_fields[$key]);
          continue;
        }
      }

      // Before we read the submitted values, store a copy of what we would
      // use if the form was empty so we can figure out which transactions are
      // just setting things to their default values for the current form.
      $defaults = array();
      foreach ($submit_fields as $key => $field) {
        $defaults[$key] = $field->getValueForTransaction();
      }

      foreach ($submit_fields as $key => $field) {
        $field->setIsSubmittedForm(true);

        if (!$field->shouldReadValueFromSubmit()) {
          continue;
        }

        $field->readValueFromSubmit($request);
      }

      $xactions = array();

      if ($this->getIsCreate()) {
        $xactions[] = id(clone $template)
          ->setTransactionType(PhabricatorTransactions::TYPE_CREATE);
      }

      foreach ($submit_fields as $key => $field) {
        $field_value = $field->getValueForTransaction();

        $type_xactions = $field->generateTransactions(
          clone $template,
          array(
            'value' => $field_value,
          ));

        foreach ($type_xactions as $type_xaction) {
          $default = $defaults[$key];

          if ($default === $field->getValueForTransaction()) {
            $type_xaction->setIsDefaultTransaction(true);
          }

          $xactions[] = $type_xaction;
        }
      }

      $editor = $object->getApplicationTransactionEditor()
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true);

      try {

        $editor->applyTransactions($object, $xactions);

        return $this->newEditResponse($request, $object, $xactions);
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $validation_exception = $ex;

        foreach ($fields as $field) {
          $xaction_type = $field->getTransactionType();
          if ($xaction_type === null) {
            continue;
          }

          $message = $ex->getShortMessage($xaction_type);
          if ($message === null) {
            continue;
          }

          $field->setControlError($message);
        }
      }
    } else {
      if ($this->getIsCreate()) {
        $template = $request->getStr('template');

        if (strlen($template)) {
          $template_object = $this->newObjectFromIdentifier(
            $template,
            array(
              PhabricatorPolicyCapability::CAN_VIEW,
            ));
          if (!$template_object) {
            return new Aphront404Response();
          }
        } else {
          $template_object = null;
        }

        if ($template_object) {
          $copy_fields = $this->buildEditFields($template_object);
          $copy_fields = mpull($copy_fields, null, 'getKey');
          foreach ($copy_fields as $copy_key => $copy_field) {
            if (!$copy_field->getIsCopyable()) {
              unset($copy_fields[$copy_key]);
            }
          }
        } else {
          $copy_fields = array();
        }

        foreach ($fields as $field) {
          if (!$field->shouldReadValueFromRequest()) {
            continue;
          }

          $field_key = $field->getKey();
          if (isset($copy_fields[$field_key])) {
            $field->readValueFromField($copy_fields[$field_key]);
          }

          $field->readValueFromRequest($request);
        }
      }
    }

    $action_button = $this->buildEditFormActionButton($object);

    if ($this->getIsCreate()) {
      $header_text = $this->getFormHeaderText($object);
    } else {
      $header_text = $this->getObjectEditTitleText($object);
    }

    $show_preview = !$request->isAjax();

    if ($show_preview) {
      $previews = array();
      foreach ($fields as $field) {
        $preview = $field->getPreviewPanel();
        if (!$preview) {
          continue;
        }

        $control_id = $field->getControlID();

        $preview
          ->setControlID($control_id)
          ->setPreviewURI('/transactions/remarkuppreview/');

        $previews[] = $preview;
      }
    } else {
      $previews = array();
    }

    $form = $this->buildEditForm($object, $fields);

    if ($request->isAjax()) {
      if ($this->getIsCreate()) {
        $cancel_uri = $this->getObjectCreateCancelURI($object);
        $submit_button = $this->getObjectCreateButtonText($object);
      } else {
        $cancel_uri = $this->getObjectEditCancelURI($object);
        $submit_button = $this->getObjectEditButtonText($object);
      }

      return $this->getController()
        ->newDialog()
        ->setWidth(AphrontDialogView::WIDTH_FULL)
        ->setTitle($header_text)
        ->setValidationException($validation_exception)
        ->appendForm($form)
        ->addCancelButton($cancel_uri)
        ->addSubmitButton($submit_button);
    }

    $header = id(new PHUIHeaderView())
      ->setHeader($header_text)
      ->addActionLink($action_button);

    $crumbs = $this->buildCrumbs($object, $final = true);

    $box = id(new PHUIObjectBoxView())
      ->setUser($viewer)
      ->setHeader($header)
      ->setValidationException($validation_exception)
      ->appendChild($form);

    return $controller->newPage()
      ->setTitle($header_text)
      ->setCrumbs($crumbs)
      ->appendChild($box)
      ->appendChild($previews);
  }

  protected function newEditResponse(
    AphrontRequest $request,
    $object,
    array $xactions) {
    return id(new AphrontRedirectResponse())
      ->setURI($this->getObjectViewURI($object));
  }

  private function buildEditForm($object, array $fields) {
    $viewer = $this->getViewer();
    $controller = $this->getController();
    $request = $controller->getRequest();

    $form = id(new AphrontFormView())
      ->setUser($viewer);

    foreach ($this->contextParameters as $param) {
      $form->addHiddenInput($param, $request->getStr($param));
    }

    foreach ($fields as $field) {
      $field->appendToForm($form);
    }

    if ($this->getIsCreate()) {
      $cancel_uri = $this->getObjectCreateCancelURI($object);
      $submit_button = $this->getObjectCreateButtonText($object);
    } else {
      $cancel_uri = $this->getObjectEditCancelURI($object);
      $submit_button = $this->getObjectEditButtonText($object);
    }

    if (!$request->isAjax()) {
      $form->appendControl(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($cancel_uri)
          ->setValue($submit_button));
    }

    return $form;
  }

  private function buildEditFormActionButton($object) {
    $viewer = $this->getViewer();

    $action_view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    foreach ($this->buildEditFormActions($object) as $action) {
      $action_view->addAction($action);
    }

    $action_button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText(pht('Configure Form'))
      ->setHref('#')
      ->setIconFont('fa-gear')
      ->setDropdownMenu($action_view);

    return $action_button;
  }

  private function buildEditFormActions($object) {
    $actions = array();

    if ($this->supportsEditEngineConfiguration()) {
      $engine_key = $this->getEngineKey();
      $config = $this->getEditEngineConfiguration();

      $can_manage = PhabricatorPolicyFilter::hasCapability(
        $this->getViewer(),
        $config,
        PhabricatorPolicyCapability::CAN_EDIT);

      if ($can_manage) {
        $manage_uri = $config->getURI();
      } else {
        $manage_uri = $this->getEditURI(null, 'nomanage/');
      }

      $view_uri = "/transactions/editengine/{$engine_key}/";

      $actions[] = id(new PhabricatorActionView())
        ->setLabel(true)
        ->setName(pht('Configuration'));

      $actions[] = id(new PhabricatorActionView())
        ->setName(pht('View Form Configurations'))
        ->setIcon('fa-list-ul')
        ->setHref($view_uri);

      $actions[] = id(new PhabricatorActionView())
        ->setName(pht('Edit Form Configuration'))
        ->setIcon('fa-pencil')
        ->setHref($manage_uri)
        ->setDisabled(!$can_manage)
        ->setWorkflow(!$can_manage);
    }

    $actions[] = id(new PhabricatorActionView())
      ->setLabel(true)
      ->setName(pht('Documentation'));

    $actions[] = id(new PhabricatorActionView())
      ->setName(pht('Using HTTP Parameters'))
      ->setIcon('fa-book')
      ->setHref($this->getEditURI($object, 'parameters/'));

    $doc_href = PhabricatorEnv::getDoclink('User Guide: Customizing Forms');
    $actions[] = id(new PhabricatorActionView())
      ->setName(pht('User Guide: Customizing Forms'))
      ->setIcon('fa-book')
      ->setHref($doc_href);

    return $actions;
  }

  final public function addActionToCrumbs(PHUICrumbsView $crumbs) {
    $viewer = $this->getViewer();

    $can_create = $this->hasCreateCapability();
    if ($can_create) {
      $configs = $this->loadUsableConfigurationsForCreate();
    } else {
      $configs = array();
    }

    $dropdown = null;
    $disabled = false;
    $workflow = false;

    $menu_icon = 'fa-plus-square';

    if (!$configs) {
      if ($viewer->isLoggedIn()) {
        $disabled = true;
      } else {
        // If the viewer isn't logged in, assume they'll get hit with a login
        // dialog and are likely able to create objects after they log in.
        $disabled = false;
      }
      $workflow = true;

      if ($can_create) {
        $create_uri = $this->getEditURI(null, 'nodefault/');
      } else {
        $create_uri = $this->getEditURI(null, 'nocreate/');
      }
    } else {
      $config = head($configs);
      $form_key = $config->getIdentifier();
      $create_uri = $this->getEditURI(null, "form/{$form_key}/");

      if (count($configs) > 1) {
        $menu_icon = 'fa-caret-square-o-down';

        $dropdown = id(new PhabricatorActionListView())
          ->setUser($viewer);

        foreach ($configs as $config) {
          $form_key = $config->getIdentifier();
          $config_uri = $this->getEditURI(null, "form/{$form_key}/");

          $item_icon = 'fa-plus';

          $dropdown->addAction(
            id(new PhabricatorActionView())
              ->setName($config->getDisplayName())
              ->setIcon($item_icon)
              ->setHref($config_uri));
        }
      }
    }

    $action = id(new PHUIListItemView())
      ->setName($this->getObjectCreateShortText())
      ->setHref($create_uri)
      ->setIcon($menu_icon)
      ->setWorkflow($workflow)
      ->setDisabled($disabled);

    if ($dropdown) {
      $action->setDropdownMenu($dropdown);
    }

    $crumbs->addAction($action);
  }

  final public function buildEditEngineCommentView($object) {
    $config = $this->loadDefaultEditConfiguration();

    if (!$config) {
      // TODO: This just nukes the entire comment form if you don't have access
      // to any edit forms. We might want to tailor this UX a bit.
      return id(new PhabricatorApplicationTransactionCommentView())
        ->setNoPermission(true);
    }

    $viewer = $this->getViewer();
    $object_phid = $object->getPHID();

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    if ($is_serious) {
      $header_text = $this->getCommentViewSeriousHeaderText($object);
      $button_text = $this->getCommentViewSeriousButtonText($object);
    } else {
      $header_text = $this->getCommentViewHeaderText($object);
      $button_text = $this->getCommentViewButtonText($object);
    }

    $comment_uri = $this->getEditURI($object, 'comment/');

    $view = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($viewer)
      ->setObjectPHID($object_phid)
      ->setHeaderText($header_text)
      ->setAction($comment_uri)
      ->setSubmitButtonName($button_text);

    $draft = PhabricatorVersionedDraft::loadDraft(
      $object_phid,
      $viewer->getPHID());
    if ($draft) {
      $view->setVersionedDraft($draft);
    }

    $view->setCurrentVersion($this->loadDraftVersion($object));

    $fields = $this->buildEditFields($object);

    $comment_actions = array();
    foreach ($fields as $field) {
      if (!$field->shouldGenerateTransactionsFromComment()) {
        continue;
      }

      $comment_action = $field->getCommentAction();
      if (!$comment_action) {
        continue;
      }

      $key = $comment_action->getKey();

      // TODO: Validate these better.

      $comment_actions[$key] = $comment_action;
    }

    $view->setCommentActions($comment_actions);

    return $view;
  }

  protected function loadDraftVersion($object) {
    $viewer = $this->getViewer();

    if (!$viewer->isLoggedIn()) {
      return null;
    }

    $template = $object->getApplicationTransactionTemplate();
    $conn_r = $template->establishConnection('r');

    // Find the most recent transaction the user has written. We'll use this
    // as a version number to make sure that out-of-date drafts get discarded.
    $result = queryfx_one(
      $conn_r,
      'SELECT id AS version FROM %T
        WHERE objectPHID = %s AND authorPHID = %s
        ORDER BY id DESC LIMIT 1',
      $template->getTableName(),
      $object->getPHID(),
      $viewer->getPHID());

    if ($result) {
      return (int)$result['version'];
    } else {
      return null;
    }
  }


/* -(  Responding to HTTP Parameter Requests  )------------------------------ */


  /**
   * Respond to a request for documentation on HTTP parameters.
   *
   * @param object Editable object.
   * @return AphrontResponse Response object.
   * @task http
   */
  private function buildParametersResponse($object) {
    $controller = $this->getController();
    $viewer = $this->getViewer();
    $request = $controller->getRequest();
    $fields = $this->buildEditFields($object);

    $crumbs = $this->buildCrumbs($object);
    $crumbs->addTextCrumb(pht('HTTP Parameters'));
    $crumbs->setBorder(true);

    $header_text = pht(
      'HTTP Parameters: %s',
      $this->getObjectCreateShortText());

    $header = id(new PHUIHeaderView())
      ->setHeader($header_text);

    $help_view = id(new PhabricatorApplicationEditHTTPParameterHelpView())
      ->setUser($viewer)
      ->setFields($fields);

    $document = id(new PHUIDocumentViewPro())
      ->setUser($viewer)
      ->setHeader($header)
      ->appendChild($help_view);

    return $controller->newPage()
      ->setTitle(pht('HTTP Parameters'))
      ->setCrumbs($crumbs)
      ->appendChild($document);
  }


  private function buildError($object, $title, $body) {
    $cancel_uri = $this->getObjectCreateCancelURI($object);

    return $this->getController()
      ->newDialog()
      ->setTitle($title)
      ->appendParagraph($body)
      ->addCancelButton($cancel_uri);
  }


  private function buildNoDefaultResponse($object) {
    return $this->buildError(
      $object,
      pht('No Default Create Forms'),
      pht(
        'This application is not configured with any forms for creating '.
        'objects that are visible to you and enabled.'));
  }

  private function buildNoCreateResponse($object) {
    return $this->buildError(
      $object,
      pht('No Create Permission'),
      pht('You do not have permission to create these objects.'));
  }

  private function buildNoManageResponse($object) {
    return $this->buildError(
      $object,
      pht('No Manage Permission'),
      pht(
        'You do not have permission to configure forms for this '.
        'application.'));
  }

  private function buildNoEditResponse($object) {
    return $this->buildError(
      $object,
      pht('No Edit Forms'),
      pht(
        'You do not have access to any forms which are enabled and marked '.
        'as edit forms.'));
  }

  private function buildNotEditFormRespose($object, $config) {
    return $this->buildError(
      $object,
      pht('Not an Edit Form'),
      pht(
        'This form ("%s") is not marked as an edit form, so '.
        'it can not be used to edit objects.',
        $config->getName()));
  }

  private function buildDisabledFormResponse($object, $config) {
    return $this->buildError(
      $object,
      pht('Form Disabled'),
      pht(
        'This form ("%s") has been disabled, so it can not be used.',
        $config->getName()));
  }

  private function buildCommentResponse($object) {
    $viewer = $this->getViewer();

    if ($this->getIsCreate()) {
      return new Aphront404Response();
    }

    $controller = $this->getController();
    $request = $controller->getRequest();

    if (!$request->isFormPost()) {
      return new Aphront400Response();
    }

    $config = $this->loadDefaultEditConfiguration();
    if (!$config) {
      return new Aphront404Response();
    }

    $fields = $this->buildEditFields($object);

    $is_preview = $request->isPreviewRequest();
    $view_uri = $this->getObjectViewURI($object);

    $template = $object->getApplicationTransactionTemplate();
    $comment_template = $template->getApplicationTransactionCommentObject();

    $comment_text = $request->getStr('comment');

    $actions = $request->getStr('editengine.actions');
    if ($actions) {
      $actions = phutil_json_decode($actions);
    }

    if ($is_preview) {
      $version_key = PhabricatorVersionedDraft::KEY_VERSION;
      $request_version = $request->getInt($version_key);
      $current_version = $this->loadDraftVersion($object);
      if ($request_version >= $current_version) {
        $draft = PhabricatorVersionedDraft::loadOrCreateDraft(
          $object->getPHID(),
          $viewer->getPHID(),
          $current_version);

        $draft
          ->setProperty('comment', $comment_text)
          ->setProperty('actions', $actions)
          ->save();
      }
    }

    $xactions = array();

    if ($actions) {
      $action_map = array();
      foreach ($actions as $action) {
        $type = idx($action, 'type');
        if (!$type) {
          continue;
        }

        if (empty($fields[$type])) {
          continue;
        }

        $action_map[$type] = $action;
      }

      foreach ($action_map as $type => $action) {
        $field = $fields[$type];

        if (!$field->shouldGenerateTransactionsFromComment()) {
          continue;
        }

        if (array_key_exists('initialValue', $action)) {
          $field->setInitialValue($action['initialValue']);
        }

        $field->readValueFromComment(idx($action, 'value'));

        $type_xactions = $field->generateTransactions(
          clone $template,
          array(
            'value' => $field->getValueForTransaction(),
          ));
        foreach ($type_xactions as $type_xaction) {
          $xactions[] = $type_xaction;
        }
      }
    }

    if (strlen($comment_text) || !$xactions) {
      $xactions[] = id(clone $template)
        ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
        ->attachComment(
          id(clone $comment_template)
            ->setContent($comment_text));
    }

    $editor = $object->getApplicationTransactionEditor()
      ->setActor($viewer)
      ->setContinueOnNoEffect($request->isContinueRequest())
      ->setContinueOnMissingFields(true)
      ->setContentSourceFromRequest($request)
      ->setIsPreview($is_preview);

    try {
      $xactions = $editor->applyTransactions($object, $xactions);
    } catch (PhabricatorApplicationTransactionNoEffectException $ex) {
      return id(new PhabricatorApplicationTransactionNoEffectResponse())
        ->setCancelURI($view_uri)
        ->setException($ex);
    }

    if (!$is_preview) {
      PhabricatorVersionedDraft::purgeDrafts(
        $object->getPHID(),
        $viewer->getPHID(),
        $this->loadDraftVersion($object));
    }

    if ($request->isAjax() && $is_preview) {
      return id(new PhabricatorApplicationTransactionResponse())
        ->setViewer($viewer)
        ->setTransactions($xactions)
        ->setIsPreview($is_preview);
    } else {
      return id(new AphrontRedirectResponse())
        ->setURI($view_uri);
    }
  }


/* -(  Conduit  )------------------------------------------------------------ */


  /**
   * Respond to a Conduit edit request.
   *
   * This method accepts a list of transactions to apply to an object, and
   * either edits an existing object or creates a new one.
   *
   * @task conduit
   */
  final public function buildConduitResponse(ConduitAPIRequest $request) {
    $viewer = $this->getViewer();

    $config = $this->loadDefaultConfiguration();
    if (!$config) {
      throw new Exception(
        pht(
          'Unable to load configuration for this EditEngine ("%s").',
          get_class($this)));
    }

    $identifier = $request->getValue('objectIdentifier');
    if ($identifier) {
      $this->setIsCreate(false);
      $object = $this->newObjectFromIdentifier($identifier);
    } else {
      $this->requireCreateCapability();

      $this->setIsCreate(true);
      $object = $this->newEditableObject();
    }

    $this->validateObject($object);

    $fields = $this->buildEditFields($object);

    $types = $this->getConduitEditTypesFromFields($fields);
    $template = $object->getApplicationTransactionTemplate();

    $xactions = $this->getConduitTransactions($request, $types, $template);

    $editor = $object->getApplicationTransactionEditor()
      ->setActor($viewer)
      ->setContentSourceFromConduitRequest($request)
      ->setContinueOnNoEffect(true);

    if (!$this->getIsCreate()) {
      $editor->setContinueOnMissingFields(true);
    }

    $xactions = $editor->applyTransactions($object, $xactions);

    $xactions_struct = array();
    foreach ($xactions as $xaction) {
      $xactions_struct[] = array(
        'phid' => $xaction->getPHID(),
      );
    }

    return array(
      'object' => array(
        'id' => $object->getID(),
        'phid' => $object->getPHID(),
      ),
      'transactions' => $xactions_struct,
    );
  }


  /**
   * Generate transactions which can be applied from edit actions in a Conduit
   * request.
   *
   * @param ConduitAPIRequest The request.
   * @param list<PhabricatorEditType> Supported edit types.
   * @param PhabricatorApplicationTransaction Template transaction.
   * @return list<PhabricatorApplicationTransaction> Generated transactions.
   * @task conduit
   */
  private function getConduitTransactions(
    ConduitAPIRequest $request,
    array $types,
    PhabricatorApplicationTransaction $template) {

    $transactions_key = 'transactions';

    $xactions = $request->getValue($transactions_key);
    if (!is_array($xactions)) {
      throw new Exception(
        pht(
          'Parameter "%s" is not a list of transactions.',
          $transactions_key));
    }

    foreach ($xactions as $key => $xaction) {
      if (!is_array($xaction)) {
        throw new Exception(
          pht(
            'Parameter "%s" must contain a list of transaction descriptions, '.
            'but item with key "%s" is not a dictionary.',
            $transactions_key,
            $key));
      }

      if (!array_key_exists('type', $xaction)) {
        throw new Exception(
          pht(
            'Parameter "%s" must contain a list of transaction descriptions, '.
            'but item with key "%s" is missing a "type" field. Each '.
            'transaction must have a type field.',
            $transactions_key,
            $key));
      }

      $type = $xaction['type'];
      if (empty($types[$type])) {
        throw new Exception(
          pht(
            'Transaction with key "%s" has invalid type "%s". This type is '.
            'not recognized. Valid types are: %s.',
            $key,
            $type,
            implode(', ', array_keys($types))));
      }
    }

    $results = array();

    if ($this->getIsCreate()) {
      $results[] = id(clone $template)
        ->setTransactionType(PhabricatorTransactions::TYPE_CREATE);
    }

    foreach ($xactions as $xaction) {
      $type = $types[$xaction['type']];

      // Let the parameter type interpret the value. This allows you to
      // use usernames in list<user> fields, for example.
      $parameter_type = $type->getConduitParameterType();

      try {
        $xaction['value'] = $parameter_type->getValue($xaction, 'value');
      } catch (Exception $ex) {
        throw new PhutilProxyException(
          pht(
            'Exception when processing transaction of type "%s".',
            $xaction['type']),
          $ex);
      }

      $type_xactions = $type->generateTransactions(
        clone $template,
        $xaction);

      foreach ($type_xactions as $type_xaction) {
        $results[] = $type_xaction;
      }
    }

    return $results;
  }


  /**
   * @return map<string, PhabricatorEditType>
   * @task conduit
   */
  private function getConduitEditTypesFromFields(array $fields) {
    $types = array();
    foreach ($fields as $field) {
      $field_types = $field->getConduitEditTypes();

      if ($field_types === null) {
        continue;
      }

      foreach ($field_types as $field_type) {
        $field_type->setField($field);
        $types[$field_type->getEditType()] = $field_type;
      }
    }
    return $types;
  }

  public function getConduitEditTypes() {
    $config = $this->loadDefaultConfiguration();
    if (!$config) {
      return array();
    }

    $object = $this->newEditableObject();
    $fields = $this->buildEditFields($object);
    return $this->getConduitEditTypesFromFields($fields);
  }

  final public static function getAllEditEngines() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass(__CLASS__)
      ->setUniqueMethod('getEngineKey')
      ->execute();
  }

  final public static function getByKey(PhabricatorUser $viewer, $key) {
    return id(new PhabricatorEditEngineQuery())
      ->setViewer($viewer)
      ->withEngineKeys(array($key))
      ->executeOne();
  }

  public function getIcon() {
    $application = $this->getApplication();
    return $application->getFontIcon();
  }

  public function loadQuickCreateItems() {
    $items = array();

    if (!$this->hasCreateCapability()) {
      return $items;
    }

    $configs = $this->loadUsableConfigurationsForCreate();

    if (!$configs) {
      // No items to add.
    } else if (count($configs) == 1) {
      $config = head($configs);
      $items[] = $this->newQuickCreateItem($config);
    } else {
      $group_name = $this->getQuickCreateMenuHeaderText();

      $items[] = id(new PHUIListItemView())
        ->setType(PHUIListItemView::TYPE_LABEL)
        ->setName($group_name);

      foreach ($configs as $config) {
        $items[] = $this->newQuickCreateItem($config)
          ->setIndented(true);
      }
    }

    return $items;
  }

  private function loadUsableConfigurationsForCreate() {
    $viewer = $this->getViewer();

    $configs = id(new PhabricatorEditEngineConfigurationQuery())
      ->setViewer($viewer)
      ->withEngineKeys(array($this->getEngineKey()))
      ->withIsDefault(true)
      ->withIsDisabled(false)
      ->execute();

    $configs = msort($configs, 'getCreateSortKey');

    return $configs;
  }

  private function newQuickCreateItem(
    PhabricatorEditEngineConfiguration $config) {

    $item_name = $config->getName();
    $item_icon = $config->getIcon();
    $form_key = $config->getIdentifier();
    $item_uri = $this->getEditURI(null, "form/{$form_key}/");

    return id(new PHUIListItemView())
      ->setName($item_name)
      ->setIcon($item_icon)
      ->setHref($item_uri);
  }

  protected function getCreateNewObjectPolicy() {
    return PhabricatorPolicies::POLICY_USER;
  }

  private function requireCreateCapability() {
    PhabricatorPolicyFilter::requireCapability(
      $this->getViewer(),
      $this,
      PhabricatorPolicyCapability::CAN_EDIT);
  }

  private function hasCreateCapability() {
    return PhabricatorPolicyFilter::hasCapability(
      $this->getViewer(),
      $this,
      PhabricatorPolicyCapability::CAN_EDIT);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getPHID() {
    return get_class($this);
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return PhabricatorPolicies::getMostOpenPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getCreateNewObjectPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }
}
