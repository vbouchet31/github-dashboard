This dashboard aims to help tracking code reviews.

It can be complex for a technical leader to follow all the comments he places
on the various pull requests of a project. It is then complex for him to know 
if his feedbacks have been actioned. This dashboard aims to 
give an overview of the pending comments or pull requests which the user 
has not approved yet.

**This dashboard does not store any information about the pull requests states or
comments. It does not update anything on Github.**

![Preview](https://github.com/vbouchet31/github-dashboard/blob/main/preview.png?raw=true)

# Configuration
Visit the settings form on /config.html

- org: The organisation owning the repository.
- repo: The repository to track.
- token (optional): It is used to authenticate API requests to github.
See [Github documentation](https://docs.github.com/en/free-pro-team@latest/github/authenticating-to-github/creating-a-personal-access-token) 
to create a token. It is mandatory if the target repository is private. 
It also increase the API limit so you can refresh more often the dashboard.

- username: The Github's username of the reviewer. It is used to determine if
the user is the author of comments, reviews, reactions, ....
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

# Technical details
The system uses GraphQL API to gather all the information in a unique POST
request.
