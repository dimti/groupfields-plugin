<?php namespace Dimti\GroupFields\FormWidgets;

use ApplicationException;
use Backend\Classes\Controller;
use Backend\Classes\FormField;
use Backend\Classes\FormWidgetBase;
use Backend\Classes\WidgetManager;
use Dimti\Mirsporta\Controllers\Products;
use Lang;
use October\Rain\Html\Helper as HtmlHelper;
use Form as FormHelper;

/**
 * FormGroup Form Widget
 */
class Group extends FormWidgetBase
{
    /**
     * @inheritDoc
     */
    protected $defaultAlias = 'group';

    public $fields = [];

    protected $allFields = [];

    /**
     * @var \Backend\Classes\WidgetManager
     */
    protected $widgetManager;

    /**
     * @var array Collection of all form widgets used in this form.
     */
    protected $formWidgets = [];

    /**
     * @var string If the field element names should be contained in an array.
     * Eg: <input name="nameArray[fieldName]" />
     */
    public $arrayName;

    /**
     * @inheritDoc
     */
    public function init()
    {
        $this->fillFromConfig([
            'fields',
            'arrayName',
        ]);

        $this->widgetManager = WidgetManager::instance();

        $this->viewPath = 'modules/backend/widgets/form/partials/';

        $this->defineFields();

        \Event::listen('backend.form.beforeRefresh', function ($form, $dataHolder) {
            $fieldValues = post($this->formField->arrayName);
            foreach ( $this->fields as $name => $config) {
                if ( array_key_exists($name, $fieldValues)) {
                    $dataHolder->data[ $name ] = post($this->formField->arrayName)[ $name ];
                }
            }
        });

        \Event::listen('backend.form.refreshFields', function ($allFields) {
            $this->defineFields();
            $this->applyFiltersFromModel();
        });

        \Event::listen('backend.form.refresh', function ($result) {
            $evenResults = [];

            if (($updateFields = post('fields')) && is_array($updateFields)) {
                foreach ($updateFields as $field) {
                    if (!isset($this->allFields[$field])) {
                        continue;
                    }

                    $fieldObject = $this->allFields[$field];

                    assert($fieldObject instanceof FormWidgetBase);

                    $evenResults['#' . $fieldObject->getId('group')] = $this->makePartial('field', ['field' => $fieldObject]);
                }
            }

            return $evenResults;
        });

        $preventBeforeSetAttributeEvent = false;

        $this->model->bindEvent('model.beforeSetAttribute', function ($attributes, $options) use (&$preventBeforeSetAttributeEvent) {
            if (!$preventBeforeSetAttributeEvent) {
                $preventBeforeSetAttributeEvent = true;

                $fieldValues = array_intersect_key(post($this->formField->arrayName, []), $this->fields);

                foreach ($this->fields as $fieldName => $config) {
                    if (!starts_with($fieldName, '_') && array_key_exists($fieldName, $fieldValues)) {
                        if (!$this->model->exists ||
                            array_key_exists($fieldName, $this->model->attributes) ||
                            $this->model->hasGetMutator($fieldName) ||
                            $this->model->hasRelation($fieldName)
                        ) {
                            if (array_key_exists($fieldName, $this->model->attributes)) {
                                if ($this->model->isJsonable($fieldName) && (!empty($fieldValues[$fieldName]) || is_array($fieldValues[$fieldName]))) {
                                    $fieldValues[$fieldName] = json_encode($fieldValues[$fieldName]);
                                }

                                $this->model->attributes[$fieldName] = $fieldValues[$fieldName];
                            } else {
                                $this->model->{$fieldName} = $fieldValues[$fieldName];
                            }
                        }
                    }
                }

                $b = '';
            }
        }, 10);
    }

    protected function defineFields()
    {
        foreach ($this->fields as $name => $config) {
            $skipField = false;

            if ($config !== null && is_array($config)) {
                if (array_key_exists('context', $config) && $config['context'] != $this->parentForm->context) {
                    $skipField = true;
                }
            }

            if (!$skipField) {
                $this->allFields[$name] = $this->makeFormField($name, $config);
            }
        }

        /*
         * Bind all form widgets to controller
         */
        foreach ($this->allFields as $field) {
            if ($field->type !== 'widget') {
                continue;
            }

            $widget = $this->makeFormFieldWidget($field);
            $widget->bindToController();
        }
    }

    /*
     * Allow the model to filter fields.
     */
    protected function applyFiltersFromModel()
    {
        if (method_exists($this->model, 'filterFields')) {
            $this->model->filterFields((object) $this->allFields, $this->getContext());
        }
    }

    /**
     * @inheritDoc
     */
    public function render()
    {
        $this->defineFields();
        $this->applyFiltersFromModel();

        $this->prepareVars();
        return $this->makePartial('plugins/dimti/groupfields/formwidgets/group/partials/group');
    }

    /**
     * Prepares the form widget view data
     */
    public function prepareVars()
    {
        $this->vars['name'] = $this->formField->getName();
        $this->vars['field'] = $this->formField;
        $this->vars['model'] = $this->model;
    }

    /**
     * @inheritDoc
     */
    public function loadAssets()
    {
        $this->addCss('css/fields.css', 'dimti.groupfields');
    }

    /**
     * @inheritDoc
     */
    public function getSaveValue($value)
    {
        $result = [];

        $arrayName = array_reverse(explode('\\', get_class($this->model)))[0];

        foreach ($this->allFields as $field) {
            $fieldName = $field->fieldName;

            $arrayName = $field->arrayName;

            if (array_key_exists($fieldName, $arrayName ? post($arrayName) : post())) {
                $result[$fieldName] = $arrayName ? post($arrayName . '.' . $fieldName) : post($fieldName);
            }
        }

        return $result;
    }

    /**
     * Creates a form field object from name and configuration.
     *
     * @param string $name
     * @param array $config
     * @return FormField
     */
    protected function makeFormField($name, $config = [])
    {
        $label = (isset($config['label'])) ? $config['label'] : null;
        list($fieldName, $fieldContext) = $this->getChildFieldName($name);

        $field = new FormField($fieldName, $label);
        if ($fieldContext) {
            $field->context = $fieldContext;
        }
        $field->arrayName = $this->formField->arrayName;
        $field->idPrefix = $this->getId();

        /*
         * Simple field type
         */
        if (is_string($config)) {
            if ($this->isFormWidget($config) !== false) {
                $field->displayAs('widget', ['widget' => $config]);
            }
            else {
                $field->displayAs($config);
            }
        }
        /*
         * Defined field type
         */
        else {
            $fieldType = isset($config['type']) ? $config['type'] : null;
            if (!is_string($fieldType) && !is_null($fieldType)) {
                throw new \ApplicationException(Lang::get(
                    'backend::lang.field.invalid_type',
                    ['type'=>gettype($fieldType)]
                ));
            }

            /*
             * Widget with configuration
             */
            if ($this->isFormWidget($fieldType) !== false) {
                $config['widget'] = $fieldType;
                $fieldType = 'widget';
            }

            $field->displayAs($fieldType, $config);
        }

        /*
         * Set field value
         */
        $field->value = $this->getFieldValue($field);

        /*
         * Check model if field is required
         */
        if (!$field->required && $this->model && method_exists($this->model, 'isAttributeRequired')) {
            $field->required = $this->model->isAttributeRequired($field->fieldName);
        }

        if ($this->model && method_exists($this->model, 'setValidationAttributeName')) {
            $this->model->setValidationAttributeName($fieldName, $label);
        }
        /*
         * Get field options from model
         */
        $optionModelTypes = ['dropdown', 'radio', 'checkboxlist', 'balloon-selector'];
        if (in_array($field->type, $optionModelTypes, false)) {
            /*
             * Defer the execution of option data collection
             */
            $field->options(function () use ($field, $config) {
                $fieldOptions = isset($config['options']) ? $config['options'] : null;
                $fieldOptions = $this->getOptionsFromModel($field, $fieldOptions);
                return $fieldOptions;
            });
        }

        return $field;
    }

    /**
     * Looks at the model for defined options.
     *
     * @param $field
     * @param $fieldOptions
     * @return mixed
     */
    protected function getOptionsFromModel($field, $fieldOptions)
    {
        /*
         * Advanced usage, supplied options are callable
         */
        if (is_array($fieldOptions) && is_callable($fieldOptions)) {
            $fieldOptions = call_user_func($fieldOptions, $this, $field);
        }

        /*
         * Refer to the model method or any of its behaviors
         */
        if (!is_array($fieldOptions) && !$fieldOptions) {
            try {
                list($model, $attribute) = $field->resolveModelAttribute($this->model, $field->fieldName);
            }
            catch (\Exception $ex) {
                throw new \ApplicationException(Lang::get('backend::lang.field.options_method_invalid_model', [
                    'model' => get_class($this->model),
                    'field' => $field->fieldName
                ]));
            }

            $methodName = 'get'.studly_case($attribute).'Options';
            if (
                !$this->objectMethodExists($model, $methodName) &&
                !$this->objectMethodExists($model, 'getDropdownOptions')
            ) {
                throw new ApplicationException(Lang::get('backend::lang.field.options_method_not_exists', [
                    'model'  => get_class($model),
                    'method' => $methodName,
                    'field'  => $field->fieldName
                ]));
            }

            if ($this->objectMethodExists($model, $methodName)) {
                $fieldOptions = $model->$methodName($field->value, $this->data);
            }
            else {
                $fieldOptions = $model->getDropdownOptions($attribute, $field->value, $this->data);
            }
        }
        /*
         * Field options are an explicit method reference
         */
        elseif (is_string($fieldOptions)) {
            if (!$this->objectMethodExists($this->model, $fieldOptions)) {
                throw new \ApplicationException(Lang::get('backend::lang.field.options_method_not_exists', [
                    'model'  => get_class($this->model),
                    'method' => $fieldOptions,
                    'field'  => $field->fieldName
                ]));
            }

            $fieldOptions = $this->model->$fieldOptions($field->value, $field->fieldName, $this->data);
        }

        return $fieldOptions;
    }

    /**
     * Internal helper for method existence checks.
     *
     * @param  object $object
     * @param  string $method
     * @return boolean
     */
    protected function objectMethodExists($object, $method)
    {
        if (method_exists($object, 'methodExists')) {
            return $object->methodExists($method);
        }

        return method_exists($object, $method);
    }

    /**
     * Check if a field type is a widget or not
     *
     * @param  string  $fieldType
     * @return boolean
     */
    protected function isFormWidget($fieldType)
    {
        if ($fieldType === null) {
            return false;
        }

        if (strpos($fieldType, '\\')) {
            return true;
        }

        $widgetClass = $this->widgetManager->resolveFormWidget($fieldType);

        if (!class_exists($widgetClass)) {
            return false;
        }

        if (is_subclass_of($widgetClass, 'Backend\Classes\FormWidgetBase')) {
            return true;
        }

        return false;
    }

    /**
     * Looks up the field value.
     * @param mixed $field
     * @return string
     */
    protected function getFieldValue($field)
    {
        if (is_string($field)) {
            if (!isset($this->allFields[$field])) {
                throw new ApplicationException(Lang::get(
                    'backend::lang.form.missing_definition',
                    compact('field')
                ));
            }

            $field = $this->allFields[$field];
        }

        $defaultValue = !$this->model->exists
            ? $field->getDefaultFromData($this->model)
            : null;

        return $field->getValueFromData($this->model, $defaultValue);
    }

    /**
     * Parses a field's name
     * @param string $field Field name
     * @return array [columnName, context]
     */
    protected function getChildFieldName($field)
    {
        if (strpos($field, '@') === false) {
            return [$field, null];
        }

        return explode('@', $field);
    }
    /**
     * Returns a HTML encoded value containing the other fields this
     * field depends on
     * @param  \Backend\Classes\FormField $field
     * @return string
     */
    protected function getFieldDepends($field)
    {
        if (!$field->dependsOn) {
            return '';
        }

        $dependsOn = is_array($field->dependsOn) ? $field->dependsOn : [$field->dependsOn];
        $dependsOn = htmlspecialchars(json_encode($dependsOn), ENT_QUOTES, 'UTF-8');
        return $dependsOn;
    }

    /**
     * Helper method to determine if field should be rendered
     * with label and comments.
     * @param  \Backend\Classes\FormField $field
     * @return boolean
     */
    protected function showFieldLabels($field)
    {
        if (in_array($field->type, ['checkbox', 'switch', 'section'])) {
            return false;
        }

        if ($field->type === 'widget') {
            $widget = $this->makeFormFieldWidget($field);
            return $widget->showLabels;
        }

        return true;
    }

    /**
     * Renders the HTML element for a field
     * @param FormWidgetBase $field
     * @return string|bool The rendered partial contents, or false if suppressing an exception
     */
    public function renderFieldElement($field)
    {
        return $this->makePartial(
            'field_' . $field->type,
            [
                'field' => $field,
                'formModel' => $this->model
            ]
        );
    }
    /**
     * Makes a widget object from a form field object.
     *
     * @param $field
     * @return \Backend\Traits\FormWidgetBase|null
     */
    protected function makeFormFieldWidget($field)
    {
        if ($field->type !== 'widget') {
            return null;
        }

        if (isset($this->formWidgets[$field->fieldName])) {
            return $this->formWidgets[$field->fieldName];
        }

        $widgetConfig = $this->makeConfig($field->config);
        $widgetConfig->alias = $this->alias . studly_case(HtmlHelper::nameToId($field->fieldName));
        $widgetConfig->sessionKey = $this->getSessionKey();
        $widgetConfig->previewMode = $this->previewMode;
        $widgetConfig->model = $this->model;
        $widgetConfig->data = $this->data;
        $widgetConfig->parentForm = $this;

        $widgetName = $widgetConfig->widget;
        $widgetClass = $this->widgetManager->resolveFormWidget($widgetName);

        if (!class_exists($widgetClass)) {
            throw new \ApplicationException(Lang::get(
                'backend::lang.widget.not_registered',
                ['name' => $widgetClass]
            ));
        }

        $widget = $this->makeFormWidget($widgetClass, $field, $widgetConfig);

        /*
         * If options config is defined, request options from the model.
         */
        if (isset($field->config['options'])) {
            $field->options(function () use ($field) {
                $fieldOptions = $field->config['options'];
                if ($fieldOptions === true) {
                    $fieldOptions = null;
                }
                $fieldOptions = $this->getOptionsFromModel($field, $fieldOptions);
                return $fieldOptions;
            });
        }

        return $this->formWidgets[$field->fieldName] = $widget;
    }

    /**
     * Returns the active session key.
     *
     * @return \Illuminate\Routing\Route|mixed|string
     */
    public function getSessionKey()
    {
        if ($this->sessionKey) {
            return $this->sessionKey;
        }

        if (post('_session_key')) {
            return $this->sessionKey = post('_session_key');
        }

        return $this->sessionKey = FormHelper::getSessionKey();
    }

    /**
     * Returns the active context for displaying the form.
     *
     * @return string
     */
    public function getContext()
    {
        return $this->context;
    }
}
