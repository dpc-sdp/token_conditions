<?php

namespace Drupal\token_conditions\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides a 'Token Matcher' condition.
 *
 * @Condition(
 *   id = "token_matcher",
 *   label = @Translation("Token Matcher"),
 * )
 */
class TokenMatcher extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Creates a new TokenMatcher instance.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Utility\Token $token
   *   Token manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ModuleHandlerInterface $module_handler, Token $token, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('module_handler'),
      $container->get('token'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['token_match'] = [
      '#title' => $this->t('Source value'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['token_match'],
      '#description' => $this->t('Enter token or string with multiple tokens to be used as source value in the evaluation.'),
      '#states' => [
        'required' => [
          [':input[name="visibility[token_matcher][value_match]"]' => ['empty' => FALSE]],
          'or',
          [':input[name="visibility[token_matcher][check_empty]"]' => ['checked' => TRUE]],
        ],
      ],
    ];

    if ($this->moduleHandler->moduleExists('token')) {
      $form['tokens'] = [
        '#title' => $this->t('Tokens'),
        '#type' => 'container',
      ];

      $form['tokens']['help'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => $this->getContentTokenTypes(),
        '#global_types' => TRUE,
        '#dialog' => TRUE,
      ];
    }
    else {
      $form['tokens'] = [
        '#markup' => $this->t("Note: You don't have the <a href='@token-url'>Token</a> module installed, so the list of available tokens isn't shown here. You don't have to install <a href='@token-url'>Token</a> to be able to use tokens, but if you have it installed, and enabled, you'll be able to enjoy an interactive tokens browser.", ['@token-url' => 'https://www.drupal.org/project/token']),
        '#weight' => 99,
      ];
    }

    $form['check_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check if value is empty'),
      '#description' => $this->t('Instead of comparing the source string with the expected value, check to see whether it evaluates to an empty/null/zero value (after replacing any tokens it contains).'),
      '#default_value' => $this->configuration['check_empty'],
    ];

    $invisible_state = [
      'invisible' => [
        ':input[name="visibility[token_matcher][check_empty]"]' => ['checked' => TRUE],
      ],
    ];

    $form['value_match'] = [
      '#title' => $this->t('Expected value'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['value_match'],
      '#description' => $this->t('Enter string to check against. This can also contain tokens.'),
      '#states' => $invisible_state,
    ];

    $form['use_regex'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use regex match'),
      '#default_value' => $this->configuration['use_regex'],
      '#states' => $invisible_state,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    return $this->t('@token_match = @value_match', [
      '@token_match' => $this->configuration['token_match'],
      '@value_match' => $this->configuration['value_match'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $token_data = $this->getTokenData();
    $token_replaced = $this->token->replace($this->configuration['token_match'], $token_data, ['clear' => TRUE]);
    $value_replace = $this->token->replace($this->configuration['value_match'], $token_data, ['clear' => TRUE]);
    if ($this->configuration['check_empty']) {
      return empty($token_replaced);
    }
    if ($this->configuration['use_regex']) {
      return (boolean) preg_match($value_replace, $token_replaced);
    }

    return $token_replaced == $value_replace;
  }

  /**
   * Gets the token type for a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityType $entity_type
   *   The entity.
   *
   * @return string
   *   The token type.
   */
  protected function getTokenType(ContentEntityType $entity_type) {
    return $entity_type->get('token type') ?: $entity_type->id();
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['token_match'] = $form_state->getValue('token_match');
    $this->configuration['check_empty'] = $form_state->getValue('check_empty');
    $this->configuration['value_match'] = $form_state->getValue('value_match');
    $this->configuration['use_regex'] = $form_state->getValue('use_regex');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'token_match' => '',
      'value_match' => '',
      'check_empty' => 0,
      'use_regex' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * Get an array of token data.
   *
   * @return array
   *   keys - entity types
   *   values - entities
   */
  protected function getTokenData() {
    $token_data = [];
    $token_types = $this->getContentTokenTypes();
    foreach ($token_types as $entity_type => $token_type) {
      if ($entity = $this->getPseudoContextValue($entity_type)) {
        $token_data[$token_type] = $entity;
      }
    }

    return $token_data;
  }

  /**
   * Get a list of available content token types.
   *
   * @return array
   *   The available content token types.
   */
  protected function getContentTokenTypes() {
    $token_types = [];
    $allEntities = $this->entityTypeManager->getDefinitions();
    foreach ($allEntities as $entity_type => $entity_type_info) {
      if ($entity_type_info instanceof ContentEntityType) {
        $token_types[$entity_type] = $this->getTokenType($entity_type_info);
      }
    }

    return $token_types;
  }

  /**
   * Get entity by type from current request.
   *
   * This is a stop-gap to until there is a better way to get the values from
   * context.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return array|false
   *   Contextual current entity, FALSE if can't be determined.
   */
  protected function getPseudoContextValue(string $entity_type) {
    $attributes = $this->requestStack->getCurrentRequest()->attributes;
    if ($attributes->has($entity_type)) {
      $entity_attribute = $attributes->get($entity_type);
      if ($entity_attribute instanceof ContentEntityInterface) {
        return $entity_attribute;
      }
    }

    return FALSE;
  }

}
