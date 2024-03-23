<?php declare(strict_types=1);

namespace App\Component;

use App\Lib\Github;
use App\Lib\Manticore;
use App\Lib\Queue;
use App\Model\Comment;
use App\Model\Issue;
use App\Model\Label;
use App\Model\Org;
use App\Model\Repo;
use App\Model\User;
use Cli;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use ReflectionClass;
use Result;
use Throwable;

use function ok;

final class Search {
	/**
	 * Get repos for organization
	 * @param  Org    $org
	 * @return Result<Repo>
	 */
	public static function getRepos(Org $org): Result {
		$repos = result(Manticore::getRepos($org->id));
		return ok(array_map(Repo::fromArray(...), $repos));
	}

	/**
	 * Get most popular repositories for showcase
	 * @param  int $limit
	 * @return Result
	 */
	public static function getShowcaseRepos(int $limit = 10): Result {
		return Manticore::getShowcaseRepos($limit);
	}

	/**
	 * Wrapper to get the organization inf
	 * @param  string $name
	 * @return Result<Repo>
	 */
	public static function getOrg(string $name): Result {
		return Manticore::findOrg($name);
	}

	/**
	 * Wrapper to get the repository
	 * @param  string $org
	 * @param  string $name
	 * @return Result<Repo>
	 */
	public static function getOrgAndRepo(string $org, string $name): Result {
		$orgResult = static::getOrg($org);
		if ($orgResult->err) {
			return $orgResult;
		}
		$org = result($orgResult);

		$repoResult = Manticore::findRepo($org->id, $name);
		if ($repoResult->err) {
			return $repoResult;
		}
		$repo = result($repoResult);
		return ok([$org, $repo]);
	}

	/**
	 * Pass the url to the github repo and fetch issue in th equeu
	 * @param  string $url
	 * @return Result<array{0:Org,1:?Repo}>
	 */
	public static function fetchIssues(string $url): Result {
		if (str_starts_with($url, 'https://github.com/')) {
			$url = substr($url, 19);
		}
		$parts = array_map(trim(...), explode('/', $url));
		if (sizeof($parts) > 1) {
			[$org, $repo] = $parts;
		} else {
			[$org] = $parts;
			$repo = null;
		}
		// If org only, return null
		if (!$repo) {
			$orgResult = Manticore::findOrg($org);
			if ($orgResult->err) {
				return $orgResult;
			}
			$org = result($orgResult);
			return ok([$org, null]);
		}

		$repoResult = static::getOrgAndRepo($org, $repo);
		$issue_count = 0;
		if ($repoResult->err) {
			try {
				$orgInfo = Github::getOrg($org);
				$repoInfo = Github::getRepo($org, $repo);
				if ($repoInfo['visibility'] !== 'public' || !$repoInfo['has_issues']) {
					return err('e_repo_not_indexable');
				}

				$issue_count = Github::getIssueCount($org, $repo);
			} catch (Throwable) {
				return err('e_repo_not_found');
			}

			$orgResult = Manticore::findOrCreateOrg(
				$orgInfo['login'], [
				'id' => $orgInfo['id'],
				'public_repos' => $orgInfo['public_repos'],
				'description' => $orgInfo['description'],
				'followers' => $orgInfo['followers'],
				'following' => $orgInfo['following'],
				]
			);
			if ($orgResult->err) {
				return $orgResult;
			}
			$org = result($orgResult);
			$repoResult = Manticore::findOrCreateRepo(
				$org->id, $repo, [
					'id' => $repoInfo['id'],
					'expected_issues' => $issue_count,
				]
			);
			if ($repoResult->err) {
				return $repoResult;
			}
			$repo = result($repoResult);
		} else {
			[$org, $repo] = result($repoResult);
		}

		// Index only we have something to index in gap of 1 min
		if ((!$repo->is_indexing || $repo->updated_at === 0) && (time() - $repo->updated_at) >= 60) {
			$repo->is_indexing = true;
			Manticore::add([$repo]);
			Queue::add('github-issue-fetch', [$org, $repo]);
		}
		return ok([$org, $repo]);
	}

	/**
	 * This method helps us to load the issues from the repository
	 * and store it in manticore
	 * @param Org $org
	 * @param  Repo   $repo
	 * @return Result
	 */
	public static function index(Org $org, Repo $repo): Result {
		$converter = new GithubFlavoredMarkdownConverter(
			[
			'html_input' => 'strip',
			'allow_unsafe_links' => true,
			]
		);
		// Refetch repo to make sure that we are not in race condition situations
		/** @var Repo $repo */
		[$org, $repo] = result(static::getOrgAndRepo($org->name, $repo->name));
		$repo->expected_issues = Github::getIssueCount($org->name, $repo->name);
		$since = $repo->updated_at;
		$users = [];
		/** @var string $since_date */
		while (true) {
			$since_date = gmdate('Y-m-d\TH:i:s\Z', $since + 1);
			$issue_count = 0;
			$pull_request_count = 0;
			$issues = Github::getIssues($org->name, $repo->name, $since_date);
			$comments = [];
			Cli::print("Since: $since_date");
			Cli::print('Issues: ' . sizeof($issues));
			if (!$issues) {
				break;
			}
			$users = [];
			$labels = [];
			foreach ($issues as &$issue) {
				Cli::print("Issue #{$issue['id']} {$issue['title']}");
				// Unsupported type in manticore, so make it string
				$issue['closed_at'] = $issue['closed_at'] ? strtotime($issue['closed_at']) : 0;
				$issue['created_at'] = strtotime($issue['created_at']);
				$issue['updated_at'] = strtotime($issue['updated_at']);
				$issue['body'] = (string)$converter->convert((string)$issue['body']);
				$issue['assignee_id'] = $issue['assignee']['id'] ?? 0;
				$issue['assignee_ids'] = array_column($issue['assignees'], 'id');
				$issue['label_ids'] = array_column($issue['labels'], 'id');
				$issue['is_pull_request'] = isset($issue['pull_request']);
				if ($issue['is_pull_request']) {
					$pull_request_count += 1;
				} else {
					$issue_count += 1;
				}

				// TODO: temporarely solution cuz manticore has bug
				if (!$issue['assignee_ids']) {
					unset($issue['assignee_ids']);
				}
				if (!$issue['label_ids']) {
					unset($issue['label_ids']);
				}

				$users[] = $issue['user'];
				if (isset($issue['assignee'])) {
					$users[] = $issue['assignee'];
				}
				if ($issue['assignees']) {
					$users = array_merge($users, $issue['assignees']);
				}

				$labels = array_merge($labels, $issue['labels']);
				// Add common parameters
				$issue['repo_id'] = $repo->id;
				$issue['user_id'] = $issue['user']['id'];
				Cli::print("Comments: {$issue['comments']}");
				if ($issue['comments'] > 0) {
					$issueComments = Github::getIssueComments($org->name, $repo->name, $issue['number']);
					foreach ($issueComments as &$comment) {
						$comment['repo_id'] = $repo->id;
						$comment['issue_id'] = $issue['id'];
						$comment['user_id'] = $comment['user']['id'];
						$comment['created_at'] = strtotime($comment['created_at']);
						$comment['updated_at'] = strtotime($comment['updated_at']);
						$comment['body'] = (string)$converter->convert((string)$comment['body']);
						$users[] = $comment['user'];
						unset($comment);
					}
					$comments = array_merge($comments, $issueComments);
				}
				$since = $issue['updated_at'];
				unset($issue);
			}

			// Deduplicate users
			$users = array_values(
				array_reduce(
					$users, function (array $carry, array $user) {
						if (!isset($carry[$user['id']])) {
							$carry[$user['id']] = $user;
						}
						return $carry;
					}, []
				)
			);

			// Deduplicate labels
			$labels = array_values(
				array_reduce(
					$labels, function (array $carry, array $label) {
						if (!isset($carry[$label['id']])) {
							$label['description'] = (string)$label['description'];
							$carry[$label['id']] = $label;
						}
						return $carry;
					}, []
				)
			);

			$since_date = gmdate('Y-m-d\TH:i:s\Z', $since);
			$repo->issues += $issue_count;
			$repo->pull_requests += $pull_request_count;
			$repo->comments += sizeof($comments);
			$repo->updated_at = $since;

			// Now add it to the storage
			$results = [
				Manticore::add(array_map(Issue::fromArray(...), $issues)),
				Manticore::add(array_map(Comment::fromArray(...), $comments)),
				Manticore::add(array_map(User::fromArray(...), $users)),
				Manticore::add(array_map(Label::fromArray(...), $labels)),
				Manticore::add([$repo]),
			];

			// Validate if we had errors or not
			foreach ($results as $result) {
				if ($result->err) {
					return $result;
				}
			}
		}

		// Update repo updated_at for future fetches
		Cli::print("Last since: $since_date");

		$repo->is_indexing = false;
		Manticore::add([$repo]);
		// We have deferred function that will update it all

		return ok();
	}

	/**
	 * Get the list of issues for requested repo
	 * @param string $query
	 * @param string $sort
	 * @param int $offset
	 * @return Result<array<Issue>>
	 */
	public static function process(string $query = '', array $filters = [], string $sort = 'best-match', int $offset = 0): Result {
		return Manticore::search($query, $filters, $sort, $offset);
	}

	/**
	 * Prepare filters
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>

	 */
	public static function prepareFilters(array $filters = []): array {
		$filtered = [];
		foreach ([Issue::class, Comment::class] as $modelClass) {
			$reflectionClass = new ReflectionClass($modelClass);
			$ns = strtolower(basename(str_replace('\\', '/', $modelClass)));
			$properties = $reflectionClass->getProperties();
			$filtered[$ns] = [];
			foreach ($properties as $property) {
				$name = $property->getName();
				$type = $property->getType();
				if (!isset($filters[$name])) {
					continue;
				}

				if (!settype($filters[$name], $type->getName())) {
					continue;
				}

				$filtered[$ns][$name] = $filters[$name];
			}
		}
		if (isset($filters['state'])) {
			$filtered['state'] = $filters['state'];
		}

		$repos = [];
		if (isset($filters['repos'])) {
			$repos = array_values(array_filter(array_unique(array_map('intval', $filters['repos']))));
		}

		// TODO: think about better implementation
		if (isset($filters['comment_ranges'])) {
			$filtered['comment_ranges'] = [];
			$range_ids = array_map('intval', $filters['comment_ranges']);
			$ranges = result(static::getCommentRanges($repos));
			foreach ($ranges as $range) {
				if (!in_array($range['id'], $range_ids)) {
					continue;
				}
				$row = [];
				if (isset($range['min'])) {
					$row['min'] = $range['min'];
				}

				if (isset($range['max'])) {
					$row['max'] = $range['max'];
				}
				$filtered['comment_ranges'][] = $row;
			}
		}

		$users = [];
		if (isset($filters['authors'])) {
			$users = array_values(array_filter(array_unique(array_map('intval', $filters['authors']))));
		}

		$filtered['issue'] = [];
		if (isset($filters['assignees'])) {
			$filtered['issue']['assignee_ids'] = array_values(array_filter(array_unique(array_map('intval', $filters['assignees']))));
		}
		if (isset($filters['labels'])) {
			$filtered['issue']['label_ids'] = array_values(array_filter(array_unique(array_map('intval', $filters['labels']))));
		}

		$search_in = $filters['index'] ?? 'everywhere';
		[$issues, $pull_requests, $comments] = match ($search_in) {
			'everywhere' => [true, true, true],
			'issues' => [true, false, false],
			'pull_requests' => [false, true, false],
			'comments' => [false, false, true],
		};

		$filtered['issues'] = $issues;
		$filtered['pull_requests'] = $pull_requests;
		$filtered['comments'] = $filtered['issue'] ? false : $comments;

		if ($users) {
			$filtered['common']['user_id'] = $users;
		}

		if ($repos) {
			$filtered['common']['repo_id'] = $repos;
		}

		return $filtered;
	}

	/**
	 * Get authors for given repo to use filtering
	 * @param  array<int> $repo_ids
	 * @return Result<array<User>>
	 */
	public static function getAuthors(array $repo_ids): Result {
		return Manticore::getUsers($repo_ids, 'user_id');
	}

	/**
	 * Get all assignees for the repo
	 * @param  array<int> $repo_ids
	 * @return Result<array<User>>
	 */
	public static function getAssignees(array $repo_ids): Result {
		return Manticore::getUsers($repo_ids, 'assignee_id');
	}

	/**
	 * Get all labels for repo
	 * @param array<int> $repo_ids
	 * @return Result<array<Label>>
	 */
	public static function getLabels(array $repo_ids): Result {
		return Manticore::getLabels($repo_ids);
	}

	/**
	 * This method returns comment ranges used for the filter
	 * @param  array<int> $repo_ids
	 * @return Result<array<mixed>>
	 */
	public static function getCommentRanges(array $repo_ids): Result {
		return Manticore::getCommentRanges($repo_ids, [3, 5, 10, 15, 20, 30, 50]);
	}


	/**
	 * Sanitize the query and prepare it, returns new one that
	 * we will use for search
	 * @param  string $query
	 * @return string
	 */
	public static function sanitizeQuery(string $query): string {
		$quote_count = substr_count($query, '"');
		if ($quote_count >= 2) {
			$query = preg_replace(['/"+/', '/\-+/', '/\/+/', '/@+/'], ['"', '-', '', ''], $query);
			if ($quote_count % 2 !== 0) {
				$in_quotes = str_starts_with($query, '"') && str_ends_with($query, '"');
				$query = trim($query, '"');
				$query = str_replace('"', '', $query);

				if ($in_quotes) {
					$query = '"' . $query . '"';
				}
			}
		}

		if ($query === '""') {
			$query = '';
		}

		return $query;
	}
}
