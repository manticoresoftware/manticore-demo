# Demo: GitHub search with Manticore Search

**Publicly available demo - [github.manticoresearch.com](https://github.manticoresearch.com/)**

[Blogpost about this project](https://manticoresearch.com/blog/manticoresearch-github-issue-search-demo/)

[Blogpost about semantic search with Manticore Search](https://manticoresearch.com/blog/github-semantic-search/)

[Blogpost about how we added autocomplete](https://manticoresearch.com/blog/github-demo-how-we-added-autocomplete/)

## Just run the demo locally

To run the project on your local machine, you need to have Docker installed with the compose plugin. Follow these commands to start:

```
git clone https://github.com/manticoresoftware/manticore-github-issue-search.git
cd manticore-github-issue-search/docker
cp .env.example .env
```

If you want to be able to crawl your github repositores, specify your [github token](https://github.com/settings/tokens) ("Generate your token" -> "classic" -> specify name -> no checkboxes -> "Generate token") in `GITHUB_TOKENS=""` in the `.env` file.

```
cd containers/manticore

# skip the following line if you just want to crawl your own repositories instead and don't need any ready data
wget https://github.com/manticoresoftware/manticore-github-issue-search/releases/download/240327/backup.tar.gz && rm -fr backup && tar xzf backup.tar.gz

cd ../..
docker compose down -v
docker compose up
```

After completing these steps, the project should be accessible at [http://localhost/](http://localhost/). If you have restored from a backup, you can open one of the crawled repositories, for example, [http://localhost/manticoresoftware/manticoresearch/](http://localhost/manticoresoftware/manticoresearch/).

## Further configuration

The default port for the server is 80, so if you need to change it, update the `nginx` section in `app/config/app.ini.tpl`.

In the `.env` file, you can set up the other variables:
- `GITHUB_TOKENS` - GitHub tokens to use for crawling
- `GMAIL_ACCOUNT` / `GMAIL_PASSWORD` - to send notifications through
- `SSL_CERT_PEM` / `SSL_CERT_KEY` - for HTTPS

## Preparing for Deployment

If you aim to use this project beyond a Manticore Search demo, such as an alternative to GitHub's issue search, there's a method for deploying it on a remote server. First, install [yoda](https://github.com/Muvon/yoda) on your machine and familiarize yourself with its documentation.

#### Setting Up Your GitHub Token

You will need a GitHub token to utilize the GitHub API. Ensure that your token is set in your environment on the user account you're using to deploy.

You should add the `GITHUB_TOKENS` to the remote server's environment. Usually, this can be done by adding it to the `~/.bashrc` file like so:

```bash
export GITHUB_TOKENS=...
```
Remember, if you haven't added any tokens, you're limited to 60 requests per hour. That's not a lot for indexing an average repository. If you started the project with Docker Compose before without a token, note that Docker Compose caches it, and you'll need to recreate all containers after making changes by running `docker compose up --force-recreate`.

#### Deployment

For deployment, tweak the `docker/Envfile` with your server details and make sure you have passwordless authentication set up with your SSH key. Then, simply run:

```bash
yoda deploy --env=production
```

#### Setting Up a New Server in 5 Steps

Please remember, deployment happens from the default branch to the server from the local machine. You can use the `--branch` flag with the `yoda` command to deploy from a different branch.

Place your public keys in the `docker/.ssh/authorized_keys` folder on the local machine.

1. Start by initializing a new server using Rocky Linux 9 as the base operating system.
2. Enter your server's IP address in the `Envfile` file on your local machine.
3. Execute the following command to prepare the server and also set up SSH keys for you:

    ```bash
    yoda setup --host=server-ip
    ```

4. Kick off the deployment process with the next command:

    ```bash
    yoda deploy --host=server-ip
    ```

    or

    ```bash
    yoda deploy --env=production --branch=main
    ```

5. Wait for it to finish, and then you're good to go!
