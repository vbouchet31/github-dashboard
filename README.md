This dashboard aims to help TA to track their code review.

It can be complex for a technical leader to follow all the comments he places
on the various pull requests of a project. It is then complex for him to know 
if his feedback have been taken in account or not. This dashboard aims to 
given an overview of the pending comments or pull requests which the leader 
has not approved yet.

This dashboard does not store any information about the pull requests states or
comments. It does not update anything on Github.

# Configuration
Visit the settings form on /config.html

- org: The organisation owning the repository.
- repo: The repository to track.
- token (optional): It is used to authenticate API requests to github.
See [Github documentation](https://docs.github.com/en/free-pro-team@latest/github/authenticating-to-github/creating-a-personal-access-token) 
to create a token. It is mandatory if the target repository is private. 
It also increase the API limit so you can refresh more often the dashboard.

- username: The Github's username of the TA. It is used to determine if the TA 
is the author of comments, reviews, reactions, ....
- approval method: The way the system will determine if a PR has been 
approved or not.
  - label: The PR is considered approved if the given label has been added by 
  the selected user. The PR is not considered approved if the label has been 
  added by another user. It is not considered approved if the selected user 
  removes the label afterwards.
  - reaction: The PR is considered approved if the given reaction has been 
  placed on the PR description. The reaction does not count for approval if 
  added anywhere else (comment content, comment reaction, ...)
  - review: The PR is considered approved if the given user gives "Approve" via 
  the review mechanism. Note that it is not possible to approve a PR which is 
  already merded, so this method does not really work when doing post-merge 
  code review.
  - comment [TODO]: The PR is considered if a specific comment is added by the 
  selected user. This comment must be added to the PR, not as a code review 
  on a file.

- hide closed: Show or hide the pull requests which have been closed without 
being merged.

# Technical details
The system uses GraphQL API to gather all the information in a unique POST
request.

# Todo-list
- Add an option to approve a pull request via a comment.
- Add a way to snooze a pull request for sometime or until it is updated.
- Ideally support both Rest API and GraphQL (some customer does not allow GraphQL but Rest)
- Add an option to track only specific target branches (to avoid reviewing feature branches for example)
- Cache the graphQL response for sometime
- Store the config in keyed array so it is possible to switch between profiles
- Add info about number of addition/deletion and files impacted