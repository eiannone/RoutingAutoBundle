<?php

/*
 * This file is part of the Symfony CMF package.
 *
 * (c) 2011-2013 Symfony CMF
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Functional\Subscriber;

use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Functional\BaseTestCase;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Blog;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Post;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Article;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\ConcreteContent;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Adapter\PhpcrOdmAdapter;
use Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\SeoArticle;

class AutoRouteListenerTest extends BaseTestCase
{
    protected function createBlog($withPosts = false)
    {
        $blog = new Blog;
        $blog->path = '/test/test-blog';
        $blog->title = 'Unit testing blog';

        $this->getDm()->persist($blog);

        if ($withPosts) {
            $post = new Post;
            $post->name = 'This is a post title';
            $post->title = 'This is a post title';
            $post->blog = $blog;
            $post->date = new \DateTime('2013/03/21');
            $this->getDm()->persist($post);
        }

        $this->getDm()->flush();
        $this->getDm()->clear();
    }

    public function testPersistBlog()
    {
        $this->createBlog();

        $autoRoute = $this->getDm()->find(null, '/test/auto-route/blog/unit-testing-blog');

        $this->assertNotNull($autoRoute);

        // make sure auto-route has been persisted
        $blog = $this->getDm()->find(null, '/test/test-blog');
        $routes = $this->getDm()->getReferrers($blog);

        $this->assertCount(1, $routes);
        $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $routes[0]);
        $this->assertEquals('unit-testing-blog', $routes[0]->getName());
        $this->assertEquals(PhpcrOdmAdapter::TAG_NO_MULTILANG, $routes[0]->getAutoRouteTag());
    }

    public function provideTestUpdateBlog()
    {
        return array(
            array(false),
            array(true),
        );
    }

    /**
     * @dataProvider provideTestUpdateBlog
     */
    public function testUpdateRenameBlog($withPosts = false)
    {
        $this->createBlog($withPosts);

        $blog = $this->getDm()->find(null, '/test/test-blog');
        // test update
        $blog->title = 'Foobar';
        $this->getDm()->persist($blog);
        $this->getDm()->flush();

        // note: The NAME stays the same, its the ID not the title
        $blog = $this->getDm()->find(null, '/test/test-blog');
        $this->assertNotNull($blog);

        $routes = $blog->routes;

        $this->assertCount(1, $routes);
        $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $routes[0]);

        $this->assertEquals('foobar', $routes[0]->getName());
        $this->assertEquals('/test/auto-route/blog/foobar', $routes[0]->getId());

        if ($withPosts) {
            $post = $this->getDm()->find(null, '/test/test-blog/This is a post title');
            $this->assertNotNull($post);

            $routes = $post->routes;

            $this->assertNotNull($routes[0]);
            $this->getDm()->refresh($routes[0]);

            $this->assertEquals('/test/auto-route/blog/foobar/2013/03/21/this-is-a-post-title', $routes[0]->getId());
        }
    }

    public function testUpdatePostNotChangingTitle()
    {
        $this->createBlog(true);

        $post = $this->getDm()->find(null, '/test/test-blog/This is a post title');
        $this->assertNotNull($post);

        $post->body = 'Test';

        $this->getDm()->persist($post);
        $this->getDm()->flush();
        $this->getDm()->clear();

        $post = $this->getDm()->find(null, '/test/test-blog/This is a post title');
        $routes = $post->routes;

        $this->assertCount(1, $routes);
        $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $routes[0]);

        $this->assertEquals('this-is-a-post-title', $routes[0]->getName());
    }

    public function testRemoveBlog()
    {
        $this->createBlog();
        $blog = $this->getDm()->find(null, '/test/test-blog');

        // test removing
        $this->getDm()->remove($blog);

        $this->getDm()->flush();

        $baseRoute = $this->getDm()->find(null, '/test/auto-route/blog');
        $routes = $this->getDm()->getChildren($baseRoute);
        $this->assertCount(0, $routes);
    }

    public function testPersistPost()
    {
        $this->createBlog(true);
        $route = $this->getDm()->find(null, '/test/auto-route/blog/unit-testing-blog/2013/03/21/this-is-a-post-title');
        $this->assertNotNull($route);

        // make sure auto-route references content
        $post = $this->getDm()->find(null, '/test/test-blog/This is a post title');
        $routes = $this->getDm()->getReferrers($post);

        $this->assertCount(1, $routes);
        $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $routes[0]);
        $this->assertEquals('this-is-a-post-title', $routes[0]->getName());
    }

    public function testUpdatePost()
    {
        $this->createBlog(true);

        // make sure auto-route references content
        $post = $this->getDm()->find(null, '/test/test-blog/This is a post title');
        $post->title = 'This is different';

        // test for issue #52
        $post->date = new \DateTime('2014-01-25');

        $this->getDm()->persist($post);
        $this->getDm()->flush();

        $routes = $this->getDm()->getReferrers($post);

        $this->assertCount(1, $routes);
        $route = $routes[0];

        $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $route);
        $this->assertEquals('this-is-different', $route->getName());

        $node = $this->getDm()->getNodeForDocument($route);
        $this->assertEquals(
            '/test/auto-route/blog/unit-testing-blog/2014/01/25/this-is-different',
            $node->getPath()
        );
    }

    public function provideMultilangArticle()
    {
        return array(
            array(
                array(
                    'en' => 'Hello everybody!',
                    'fr' => 'Bonjour le monde!',
                    'de' => 'Gutentag',
                    'es' => 'Hola todo el mundo',
                ),
                array(
                    'test/auto-route/articles/en/hello-everybody',
                    'test/auto-route/articles/fr/bonjour-le-monde',
                    'test/auto-route/articles/de/gutentag',
                    'test/auto-route/articles/es/hola-todo-el-mundo',
                ),
            ),
        );
    }

    /**
     * @dataProvider provideMultilangArticle
     */
    public function testMultilangArticle($data, $expectedPaths)
    {
        $article = new Article;
        $article->path = '/test/article-1';
        $this->getDm()->persist($article);

        foreach ($data as $lang => $title) {
            $article->title = $title;
            $this->getDm()->bindTranslation($article, $lang);
        }

        $this->getDm()->flush();
        $this->getDm()->clear();

        $articleTitles = array_values($data);
        $locales = array_keys($data);

        foreach ($expectedPaths as $i => $expectedPath) {
            $expectedLocale = $locales[$i];

            $route = $this->getDm()->find(null, $expectedPath);

            $this->assertNotNull($route);
            $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $route);
            $this->assertEquals($expectedLocale, $route->getAutoRouteTag());

            $content = $route->getContent();

            $this->assertNotNull($content);
            $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Article', $content);

            // We havn't loaded the translation for the document, so it is always in the default language
            $this->assertEquals('Hello everybody!', $content->title);
        }
    }

    public function provideUpdateMultilangArticle()
    {
        return array(
            array(
                array(
                    'en' => 'Hello everybody!',
                    'fr' => 'Bonjour le monde!',
                    'de' => 'Gutentag',
                    'es' => 'Hola todo el mundo',
                ),
                array(
                    'test/auto-route/articles/en/hello-everybody',
                    'test/auto-route/articles/fr/bonjour-le-monde',
                    'test/auto-route/articles/de/gutentag-und-auf-wiedersehen',
                    'test/auto-route/articles/es/hola-todo-el-mundo',
                ),
            ),
        );
    }

    /**
     * @dataProvider provideUpdateMultilangArticle
     */
    public function testUpdateMultilangArticle($data, $expectedPaths)
    {
        $article = new Article;
        $article->path = '/test/article-1';
        $this->getDm()->persist($article);

        foreach ($data as $lang => $title) {
            $article->title = $title;
            $this->getDm()->bindTranslation($article, $lang);
        }

        $this->getDm()->flush();

        $article_de = $this->getDm()->findTranslation('Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Article', '/test/article-1', 'de');
        $article_de->title .= '-und-auf-wiedersehen';
        $this->getDm()->bindTranslation($article_de, 'de');
        $this->getDm()->persist($article_de);

        $this->getDm()->flush();

        $article_de = $this->getDm()->findTranslation('Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Article', '/test/article-1', 'de');
        $routes = $this->getDm()->getReferrers($article_de);
        $this->assertCount(count($data), $routes);

        $this->getDm()->clear();

        foreach ($expectedPaths as $i => $expectedPath) {
            $route = $this->getDm()->find(null, $expectedPath);

            $this->assertNotNull($route);
            $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $route);

            $content = $route->getContent();

            $this->assertNotNull($content);
            $this->assertInstanceOf('Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\Article', $content);

            // We havn't loaded the translation for the document, so it is always in the default language
            $this->assertEquals('Hello everybody!', $content->title);
        }
    }

    public function provideLeaveRedirect()
    {
        return array(
            array(
                array(
                    'en' => 'Hello everybody!',
                    'fr' => 'Bonjour le monde!',
                    'de' => 'Gutentag',
                    'es' => 'Hola todo el mundo',
                ),
                array(
                    'en' => 'Goodbye everybody!',
                    'fr' => 'Aurevoir le monde!',
                    'de' => 'Auf weidersehn',
                    'es' => 'Adios todo el mundo',
                ),
                array(
                    'test/auto-route/seo-articles/en/hello-everybody',
                    'test/auto-route/seo-articles/fr/bonjour-le-monde',
                    'test/auto-route/seo-articles/de/gutentag',
                    'test/auto-route/seo-articles/es/hola-todo-el-mundo',
                ),
                array(
                    'test/auto-route/seo-articles/en/goodbye-everybody',
                    'test/auto-route/seo-articles/fr/aurevoir-le-monde',
                    'test/auto-route/seo-articles/de/aud-weidersehn',
                    'test/auto-route/seo-articles/es/adios-todo-el-mundo',
                ),
            ),
        );
    }

    /**
     * @dataProvider provideLeaveRedirect
     */
    public function testLeaveRedirect($data, $updatedData, $expectedRedirectRoutePaths, $expectedAutoRoutePaths)
    {
        $article = new SeoArticle;
        $article->title = 'Hai';
        $article->path = '/test/article-1';
        $this->getDm()->persist($article);

        foreach ($data as $lang => $title) {
            $article->title = $title;
            $this->getDm()->bindTranslation($article, $lang);
        }

        $this->getDm()->flush();

        foreach ($updatedData as $lang => $title) {
            $article = $this->getDm()->findTranslation('Symfony\Cmf\Bundle\RoutingAutoBundle\Tests\Resources\Document\SeoArticle', '/test/article-1', $lang);
            $article->title = $title;
            $this->getDm()->bindTranslation($article, $lang);
        }

        $this->getDm()->persist($article);
        $this->getDm()->flush();

        foreach ($expectedRedirectRoutePaths as $originalPath) {
            $redirectRoute = $this->getDm()->find('Symfony\Cmf\Bundle\RoutingBundle\Model\RedirectRoute', $originalPath);
            $this->assertNotNull($redirectRoute, 'Redirect exists for: ' . $originalPath);
        }

        foreach ($expectedAutoRoutePaths as $newPath) {
            $autoRoute = $this->getDm()->find('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $newPath);
            $this->assertNotNull($redirectRoute, 'Autoroute exists for: ' . $originalPath);
        }
    }

    /**
     * Ensure that we can map parent classes: #56
     */
    public function testParentClassMapping()
    {
        $content = new ConcreteContent();
        $content->path = '/test/content';
        $content->title = 'Hello';
        $this->getDm()->persist($content);
        $this->getDm()->flush();

        $this->getDm()->refresh($content);

        $routes = $content->routes;

        $this->assertCount(1, $routes);
    }

    public function testConflictResolverAutoIncrement()
    {
        $this->createBlog();
        $blog = $this->getDm()->find(null, '/test/test-blog');

        $post = new Post;
        $post->name = 'Post 1';
        $post->title = 'Same Title';
        $post->blog = $blog;
        $post->date = new \DateTime('2013/03/21');
        $this->getDm()->persist($post);
        $this->getDm()->flush();

        $post = new Post;
        $post->name = 'Post 2';
        $post->title = 'Same Title';
        $post->blog = $blog;
        $post->date = new \DateTime('2013/03/21');
        $this->getDm()->persist($post);
        $this->getDm()->flush();

        $post = new Post;
        $post->name = 'Post 3';
        $post->title = 'Same Title';
        $post->blog = $blog;
        $post->date = new \DateTime('2013/03/21');
        $this->getDm()->persist($post);
        $this->getDm()->flush();

        $expectedRoutes = array(
            '/test/auto-route/blog/unit-testing-blog/2013/03/21/same-title',
            '/test/auto-route/blog/unit-testing-blog/2013/03/21/same-title-1',
            '/test/auto-route/blog/unit-testing-blog/2013/03/21/same-title-2',
        );

        foreach ($expectedRoutes as $expectedRoute) {
            $route = $this->getDm()->find('Symfony\Cmf\Bundle\RoutingAutoBundle\Model\AutoRoute', $expectedRoute);
            $this->assertNotNull($route);
        }
    }

    /**
     * @expectedException Symfony\Cmf\Component\RoutingAuto\ConflictResolver\Exception\ExistingUrlException
     */
    public function testConflictResolverDefaultThrowException()
    {
        $blog = new Blog;
        $blog->path = '/test/test-blog';
        $blog->title = 'Unit testing blog';
        $this->getDm()->persist($blog);
        $this->getDm()->flush();

        $blog = new Blog;
        $blog->path = '/test/test-blog-the-second';
        $blog->title = 'Unit testing blog';
        $this->getDm()->persist($blog);
        $this->getDm()->flush();
    }
}
