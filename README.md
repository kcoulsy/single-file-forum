# Single-File PHP Forum

This project is an EXPERIMENT and is in no way shape or form something that should be used in production!

I wanted to build a fairly complex web application (a full featured forum) using nothing but a single index.php file. I want to build it complex enough to reach 10k lines of code, without installing any dependancies (though js packages through external cdn's is fine)

The inspiration came from [this video](https://www.youtube.com/watch?v=dCuefNScYKM&t=1s&pp=ygUPc2luZ2xlIHBocCBmaWxl) and has been a fairly interesting project. Honestly it hasn't been terrible but I think even if you built procedurally and just had very basic file splitting would just make things so much easier to navigate.

Features Complete:
- Basic login register
- Create forum categories, see total topics, posts, latest post
- Create forum posts, comments
- Viewing all users
- Viewing profiles, as well as their posts
- Update username
- Update password

Future features:
- Link posts in replies
- Basic RBAC
- Site settings panel
- Banning users
- Editing, removing posts
- Avatar uploading
- Pagination on categories, posts, profiles, users


Still thinking whether to rip out tailwind and handroll the styling, and need to explore a lighter wysiwyg 