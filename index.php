<?php

require_once __DIR__ . '/vendor/autoload.php';

use Custom\Github\ResultPagerLimit;
use Github\Client as GithubClient;
use Github\HttpClient\Message\ResponseMediator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Psr7\Response;
use SleekDB\SleekDB;
use Symfony\Component\Yaml\Yaml;

try {
  // Get the config from the yml file.
  $config = Yaml::parseFile(__DIR__ . '/config.yml');

  if (empty($config['org']) || empty($config['repo']) || empty($config['username'])) {
    throw new Exception('Please configure the config.yml. Org, repo and username are mandatory values.');
  }

  if (!file_exists(__DIR__ . '/data/' . $config['repo'])) {
    mkdir(__DIR__ . '/data/' . $config['repo'], 0777, TRUE);
  }

  // Prepare the connection to local stores.
  $comments_store = SleekDB::store('comments',__DIR__ . '/data/' . $config['repo']);
  $pulls_store = SleekDB::store('pulls', __DIR__ . '/data/' . $config['repo']);
  $temp_store = SleekDB::store('temp', __DIR__ . '/data/' . $config['repo']);

  // @TODO: Add an option to bypass cache duration but keep using locale data.
  // @TODO: no_cache to bypass cache duration.
  // @TODO: cache_rebuild to drop locale data and rebuild everything.
  if (isset($_GET['cache_reset'])) {
    $comments_store->delete();
    $pulls_store->delete();
    $temp_store->delete();
  }

  $last_update = $temp_store->where('key', '=', 'last_update')->fetch();
  if (empty($last_update) || (($last_update[0]['value'] + $config['cache_duration']) < time())) {
    // We delete the last_update timestamp so if there is any error in the
    // update process, the system will consider the data as outdated on next
    // execution.
    $temp_store->where('key', '=', 'last_update')->delete();

    // Prepare the Github client.
    $github_client = new GithubClient();
    if (!empty($config['token'])) {
      $github_client->authenticate( $config['token'],null, GithubClient::AUTH_ACCESS_TOKEN);
    }

    // Fetch the latest pull requests.
    $fetcher = new ResultPagerLimit($github_client, 100, 4);
    $pulls = $fetcher->fetchAll($github_client->api('pull_request'), 'all', [$config['org'], $config['repo'], ['state' => 'all']]);

    foreach ($pulls as $pull) {
      $p = $pulls_store->where('id', '=', $pull['number'])->fetch();

      // If the pull approval state is not known, we fetch the reactions.
      // The PR is considered approved if there is "+1" added by the current user.
      if (!empty($p) || !isset($p[0]['approved']) || $p[0]['approved'] == '_none') {
        $reactions = ResponseMediator::getContent($github_client->getHttpClient()->get('repos/' . $config['org'] . '/' . $config['repo'] . '/issues/' . $pull['number'] . '/reactions', ['accept' => 'application/vnd.github.squirrel-girl-preview']));

        $p[0]['approved'] = '_none';
        foreach ($reactions as $reaction) {
          if ($reaction['user']['login'] == $config['username'] && $reactions['content'] = '+1') {
            $p[0]['approved'] = 'approved';
          }
        }
      }

      if (isset($p[0]['id'])) {
        $pulls_store->where('id', '=', $pull['number'])->update(
          [
            'title' => htmlentities($pull['title']),
            'state' => $pull['state'],
            'author' => $pull['user']['login'],
            'created' => $pull['created_at'],
            'approved' => $p[0]['approved'],
            '_updated' => time(),
          ]
        );
      }
      else {
        $pulls_store->insert(
          [
            'id' => $pull['number'],
            'title' => htmlentities($pull['title']),
            'state' => $pull['state'],
            'author' => $pull['user']['login'],
            'created' => $pull['created_at'],
            'approved' => $p[0]['approved'],
            '_updated' => time(),
          ]
        );
      }
    }

    // Fetch the latest comments.
    $comments = $fetcher->fetchAll($github_client->api('pulls')->comments(), 'all', [$config['org'], $config['repo'], null, ['per_page' => 100, 'sort' => 'created', 'direction' => 'desc']]);

    foreach ($comments as $comment) {
      $exploded_pr_url = explode('/', $comment['pull_request_url']);

      if (!empty($comments_store->where('id', '=', $comment['id'])->fetch())) {
        $comments_store->where('id', '=', $comment['id'])->update(
          [
            'body' => htmlentities($comment['body']),
            'author' => $comment['user']['login'],
            'created' => $comment['created_at'],
            'in_reply_to_id' => $comment['in_reply_to_id'] ?? '_none',
            'pr_id' => array_pop($exploded_pr_url),
            '_updated' => time(),
          ]
        );
      }
      else {
        $comments_store->insert(
          [
            'id' => $comment['id'],
            'body' => htmlentities($comment['body']),
            'author' => $comment['user']['login'],
            'created' => $comment['created_at'],
            'in_reply_to_id' => $comment['in_reply_to_id'] ?? '_none',
            'pr_id' => array_pop($exploded_pr_url),
            'state' => 'open',
            '_updated' => time(),
          ]
        );
      }
    }

    // Update the comments status.
    $comments = $comments_store->where('state', '=', '_none')->orWhere('state', '=', 'open')->fetch();
    $prs_to_comments = [];
    foreach ($comments as $comment) {
      $prs_to_comments[$comment['pr_id']][$comment['id']] = $comment['state'];
    }

    $guzzle_client = new GuzzleClient();
    $promises = (function () use ($prs_to_comments, $config, $guzzle_client) {
      foreach ($prs_to_comments as $pr_id => $comments) {
        $url = 'https://github.com/' . $config['org'] . '/' . $config['repo'] . '/pull/' . $pr_id;
        $options = [];
        if (!empty($config['user_session_cookie'])) {
          $options = [
            'headers' => [
              'Cookie' =>  'user_session=' . $config['user_session_cookie'],
            ],
          ];
        }
        yield $guzzle_client->getAsync($url, $options)->then(function (Response $response) use ($pr_id) {
          return $response->withHeader('x-pr-id', $pr_id);
        });
      }
    })();

    $each = new EachPromise($promises, [
      'concurrency' => 10,
      'fulfilled' => function (Response $response) use (&$prs_to_comments, $comments_store) {
        $pr_id = $response->getHeader('x-pr-id')[0];

        $body = $response->getBody()->getContents();
        foreach ($prs_to_comments[$pr_id] as $comment_id => $comment_status) {
          $state = boolval(strpos($body, 'id="details-discussion_r' . $comment_id));
          if (!empty($comments_store->where('id', '=', $comment_id)->fetch())) {
            $comments_store->where('id', '=', $comment_id)->update(
              [
                '_updated' => time(),
                'state' => $state ? 'open' : 'closed',
              ]
            );
          }
        }
      }
    ]);
    $each->promise()->wait();

    // We store the last_update timestamp.
    $temp_store->insert(
      [
        'key' => 'last_update',
        'value' => time(),
      ]
    );
  }

  echo '<html>';
  echo '<script type="text/javascript" src="https://code.jquery.com/jquery-3.5.1.js"></script>';
  echo '<script src="https://cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js" /></script>';
  echo '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.23/css/jquery.dataTables.min.css">';
  echo '<script type="text/javascript" class="init">
    $(document).ready(function() {
      $("#results").DataTable( {
        //"lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]]
      });
    });
  </script>';

  $pulls = $pulls_store->orderBy('desc', 'id')->limit(200)->fetch();

  echo '<table id="results">';
  echo '<thead>';
  echo '<tr><th>#</th><th>Title</th><th>Author</th><th>Date</th><th>State</th><th>Approved</th><th>Pending comments</th></tr>';
  echo '</thead>';
  echo '<tbody>';
  foreach ($pulls as $pull) {
    // Get all the comments which the user has initiated and which are open.
    $comments = $comments_store
      ->where('pr_id', '=', $pull['id'])
      ->where('author', '=', $config['username'])
      ->where('state', '=', 'open')
      ->where('in_reply_to_id', '=', '_none')
      ->fetch();

    // List the PR only if it is not approved or if it has pending comments.
    if ($pull['state'] != 'closed' || $pull['approved'] == '_none' || !empty($comments)) {
      echo '<tr>';
      echo '<td>' . $pull['id'] . '</td>';
      echo '<td><a href="https://github.com/' . $config['org'] . '/' . $config['repo'] . '/pull/' . $pull['id'] . '" target="_blank">' . $pull['title'] . '</a></td>';
      echo '<td>' . $pull['author'] . '</td>';
      echo '<td>' . $pull['created'] . '</td>';
      echo '<td>' . $pull['state'] . '</td>';
      echo '<td>' . (($pull['approved'] == 'approved') ? 'Yes' : 'No') . '</td>';
      echo '<td>' . count($comments) . '</td>';
      echo '</tr>';
    }
  }
  echo '</tbody>';
  echo '</table>';

  die;
  //$comments_store->delete();





  //$comments = $github_client->api('pulls')->comments()->all($config['org'], $config['repo']);

  //$pulls = array_combine(array_column($pulls, 'url'), $pulls);

  //$comments_ids = array_column($comments, 'id');

  $known_status = $comments_store->in('id', $comments_ids)->fetch();

  //var_dump($known_status);
  //die;

  $prs_to_comments = [];
  foreach ($comments as $comment) {
    // @TODO: Get the comments which we already know the status.
    $ex = explode('/', $comment['pull_request_url']);
    $prs_to_comments[array_pop($ex)][$comment['id']] = NULL;
  }



  /*$prs_to_comments = [
    2 => [
      555685107 => NULL,
    ],
    4 => [
      555694391 => NULL,
      555706810 => NULL,
    ],
  ];*/



  echo '<table>';
  echo '<thead><td>#</td><td>Comment</td><td>File</td><td>Date</td><td>Status</td><td>PR title</td></thead>';

  foreach ($comments as $comment) {
    echo '<tr>';
    echo '<td>' . $comment['id'] . '</td>';
    echo '<td>' . substr($comment['body'], 0,100) . '</td>';
    echo '<td>' . $comment['path'] . '</td>';
    echo '<td>' . $comment['created_at'] . '</td>';
    echo '<td>' . ($prs_to_comments[$pulls[$comment['pull_request_url']]['number']][$comment['id']] ? 'Open' : 'Resolved') . '</td>';
    echo '<td>#' . $pulls[$comment['pull_request_url']]['number'] . ' ' . $pulls[$comment['pull_request_url']]['title'] . '</td>';
    echo '</tr>';
  }

  echo '</table>';
}
catch (Exception $e) {
  echo $e->getMessage();
}
