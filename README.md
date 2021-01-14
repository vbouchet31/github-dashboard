This tool aims to help TA to track their code review.

# Setup
Copy the ``config.yml.template`` to ``config.yml`` and follow "Configuration".

# Assumptions
Because not all the TA are using the same way to mark a pull request as "approved",
the assumption (for now) is that the TA leave a "+1" reaction to the pull request
description.

Pull requests are hidden when they are merged AND approved by the TA AND don't
have any pending comment opened by the TA.

The system will "only" check the latest 400 PRs and latest 400 comments.

# Configuration
- username: It is used to determine if the TA is the author of a comment or approval.
- token (optional): It is used to authenticate API requests to github.
See [Github documentation](https://docs.github.com/en/free-pro-team@latest/github/authenticating-to-github/creating-a-personal-access-token) 
to create a token. It is mandatory if the target repository is private. 
It also increase the API limit so you can refresh more often the dashboard.
- user_session_cookie (optional): It is used to authenticated some GET requests.
See technical details about the reason. To get this, authenticate on Github and 
then with dev tool, get the "user_session" cookie. It is mandatory if the target 
repository is private.
- org: The organisation owning the repository.
- repo: The repository to track.
- cache_duration: The duration (in seconds) for which the data are considered fresh.
If the page is refreshed before the cache expire, the locale data will be used. Use ?cache_reset
in the url to bypass the cache. Be cautious as building the data may require a lot
of API/GET requests. Default to 10 minutes.

# Technical details
The data are stored locally using [SleekDB](https://sleekdb.github.io/) which is
a simple and basic NoSQL database implementation.

One "table" is used to store the data about the pull requests:
- id: The id of the pull request
- title: The title of the pull request
- state: The state of the pull request (either "open" or "closed")
- author: The username of the author of the pull request
- created: The date the pull request has been opened
- approved: If the current user has approved the pull request

One "table" is used to store the data about the comments:
- id: The id of the comment
- body: The content of the comment
- author: The author of the comment
- created: The date the comment has been posted
- in_reply_to_id: The id of the comment this one is a response to. "_none" if it
is not a reply
- pr_id: The id of the pull request's the comment belongs to
- state: Either the comment (or thread) is marked as resolved or not (either "open" or "closed")

Because the comment state is not part of the Rest API, the process will issue one GET
request for each pull request which has comment in open state. It will inspect the DOM
to determine the state of the comment.