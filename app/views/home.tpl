<container class="landing" data-component="github-repo-form">
	<form action="/" method="post">
		<grid>
			<cell class="center" span="6" start="4">
				<a href="https://github.com/manticoresoftware" target="_blank" rel="noopener noreferrer">{>logo/github}</a>
			</cell>
			<cell span="6" start="4">
				<div class="input-wrapper">
					<span class="icon">
						<svg aria-hidden="true" focusable="false" role="img" viewBox="0 0 16 16" width="16" height="16" fill="currentColor" style="display: inline-block; user-select: none; vertical-align: text-bottom; overflow: visible;"><path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"></path></svg>
					</span>
					<input class="unstyled" type="text" name="url" value="{url:html}" placeholder="GitHub repository URL"/>
					<button class="unstyled icon" type="submit">
						{>icon/search}
					</button>
				</div>
			</cell>
			<cell class="center" span="6" start="4">
				<p>Try your own GitHub repo or check Manticore Search's:</p>
			</cell>
			<cell class="center showcase" span="6" start="4">
				<ul>
					{showcase}
					<li><a href="/{org.name}/{repo.name}/">{repo.name}</a></li>
					{/showcase}
				</ul>
			</cell>
			<cell class="center" span="6" start="4">
				<a href="https://manticoresearch.com" target="_blank" rel="noopener noreferrer">{>logo/manticore}</a>
			</cell>
			<row class="center">
				<p><a href="https://github.com/manticoresoftware/manticore-github-issue-search" target="_blank" rel="noopener noreferrer">{>icon/github} Code on GitHub</a></p>
			</row>
		</grid>
	</form>
</container>
