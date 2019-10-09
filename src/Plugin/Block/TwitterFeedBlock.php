<?php

namespace Drupal\twitter_feed\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;

/**
 * Provides a 'TwitterFeedBlock' block.
 *
 * @Block(
 *  id = "twitter_feed_block",
 *  admin_label = @Translation("Twitter feed block"),
 * )
 */
class TwitterFeedBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * GuzzleHttp\Client definition.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Construct.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        Client $http_client
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default = [
      'number_of_tweets' => 3,
      'display_number_of_tweets' => 3,
      'tweet_mode', FALSE,
      'username' => 'drupal',
      'display_images' => FALSE,
      'display_avatars' => FALSE,
      'display_retweets' => TRUE,
      'exclude_replies' => FALSE,
      'strip_anchors' => FALSE,
      'block_cache_time' => 3600,
    ];
    return $default;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['number_of_tweets'] = [
      '#type' => 'number',
      '#title' => $this->t('Fetch Number of tweets'),
      '#description' => $this->t('Fetch this number of tweets (includes deleted, reweets, and replies in this count)'),
      '#default_value' => $this->configuration['number_of_tweets'],
    ];
    $form['display_number_of_tweets'] = [
      '#type' => 'number',
      '#title' => $this->t('Display number of tweets'),
      '#description' => $this->t('Display only this number of tweets'),
      '#default_value' => $this->configuration['display_number_of_tweets'],
    ];
    $form['tweet_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use extended tweet mode'),
      '#description' => $this->t('Extended mode is required to fetch tweets up to 280 characters'),
      '#default_value' => $this->configuration['tweet_mode'],
    ];
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username to display'),
      '#description' => $this->t('User to fetch and display tweets'),
      '#default_value' => $this->configuration['username'],
    ];
    $form['display_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display images'),
      '#description' => $this->t('If images embedded in the tweet should be expanded and embedded'),
      '#default_value' => $this->configuration['display_images'],
    ];
    $form['display_avatars'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display avatars'),
      '#description' => $this->t("If tweeter's avatar should be displayed"),
      '#default_value' => $this->configuration['display_avatars'],
    ];
    $form['display_retweets'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Display retweets'),
      '#description' => $this->t("If retweets should be displayed (number of tweets displayed may be affected if disabled). If a fixed number of tweets are required, fetch more than needed and display a fixed number."),
      '#default_value' => $this->configuration['display_retweets'],
    );
    $form['exclude_replies'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude replies'),
      '#description' => $this->t("If replies should be excluded (number of tweets displayed may be affected if enabled). If a fixed number of tweets are required, fetch more than needed and display a fixed number."),
      '#default_value' => $this->configuration['exclude_replies'],
    );
    $form['strip_anchors'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Strip anchors'),
      '#description' => $this->t("Strip anchor tags out of tweets and trailing link to any linked resources"),
      '#default_value' => $this->configuration['strip_anchors'],
    );
    $form['block_cache_time'] = array(
      '#type' => 'number',
      '#title' => $this->t('Cache time'),
      '#description' => $this->t("Number of seconds to cache this block"),
      '#default_value' => $this->configuration['block_cache_time'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['number_of_tweets'] = $form_state->getValue('number_of_tweets');
    $this->configuration['display_number_of_tweets'] = $form_state->getValue('display_number_of_tweets');
    $this->configuration['tweet_mode'] = $form_state->getValue('tweet_mode');
    $this->configuration['username'] = $form_state->getValue('username');
    $this->configuration['display_images'] = $form_state->getValue('display_images');
    $this->configuration['display_avatars'] = $form_state->getValue('display_avatars');
    $this->configuration['display_retweets'] = $form_state->getValue('display_retweets');
    $this->configuration['exclude_replies'] = $form_state->getValue('exclude_replies');
    $this->configuration['strip_anchors'] = $form_state->getValue('strip_anchors');
    $this->configuration['block_cache_time'] = $form_state->getValue('block_cache_time');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('twitter_feed.settings');
    // https://dev.twitter.com/oauth/application-only
    $api_key = rawurlencode($config->get('twitter_feed_api_key'));
    $api_secret = rawurlencode($config->get('twitter_feed_api_secret'));
    if (!$api_key || !$api_secret) {
      return ['#markup' => $this->t('API Key or Secret missing for Twitter Feed.')];
    }
    $encoded_key = base64_encode("$api_key:$api_secret");
    $headers = [
      'Authorization' => "Basic $encoded_key",
      'Content-Type' => 'application/x-www-form-urlencoded',
    ];
    $options = [
      'headers' => $headers,
      'timeout' => 10,
      'form_params' => [
        'grant_type' => 'client_credentials',
      ],
      'referer' => TRUE,
      'allow_redirects' => TRUE,
      'decode_content' => 'gzip',
    ];

    try {
      // Get the access token first.
      // https://dev.twitter.com/oauth/reference/post/oauth2/token
      $res = $this->httpClient->post('https://api.twitter.com/oauth2/token', $options);
      $body = json_decode($res->getBody());
      $access_token = $body->access_token;

      // Now get the tweets.
      // https://dev.twitter.com/rest/reference/get/statuses/user_timeline
      $options['headers']['Authorization'] = "{$body->token_type} $access_token";
      unset($options['headers']['Content-Length']);
      unset($options['form_params']);
      $query = http_build_query([
        'screen_name' => $this->configuration['username'],
        'count' => $this->configuration['number_of_tweets'],
        'include_rts' => $this->configuration['display_retweets'],
        'exclude_replies' => $this->configuration['exclude_replies'],
        'tweet_mode' => ( $this->configuration['tweet_mode'] ? 'extended' : 'compatibility' ),
      ]);
      // Fetches the tweets.
      $res = $this->httpClient->get("https://api.twitter.com/1.1/statuses/user_timeline.json?$query", $options);
    }
    catch (RequestException $e) {
      return ['#markup' => $this->t('Error fetching tweets:') . $e->getMessage()];
    }

    $renderable_tweets = [];
    foreach (json_decode($res->getBody()) as $tweet_object) {
      $renderable_tweet = [
        '#theme' => 'twitter_feed_item',
        '#tweet' => $tweet_object,
      ];
      $language = \Drupal::config('twitter_feed.settings')->get('twitter_feed_jquery_timeago_locale');
      $renderable_tweet['#attached']['library'][] = 'twitter_feed/timeago';
      if ($language) {
        $renderable_tweet['#attached']['library'][] = 'twitter_feed/timeago_' . $language;
      }
      $renderable_tweets[] = $renderable_tweet;
    }
    if (empty($renderable_tweets)) {
      return ['#markup' => $this->t('Error fetching or rendering tweets.')];
    }
    if ($this->configuration['display_number_of_tweets'] < count($renderable_tweets) &&
        $this->configuration['display_number_of_tweets'] < $this->configuration['number_of_tweets']) {
      $renderable_tweets = array_slice($renderable_tweets, 0, $this->configuration['display_number_of_tweets']);
    }

    $build['twitter_feed_items'] = [
      '#theme' => 'twitter_feed',
      '#items' => $renderable_tweets,
    ];

    // Cache block
    $build['#cache']['keys'] = ['twitter_feed', $this->configuration['username'], "count:$this->configuration['number_of_tweets']"];
    $build['#cache']['max-age'] = $this->configuration['block_cache_time'];

    return $build;
  }

}
