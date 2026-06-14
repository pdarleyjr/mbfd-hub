FROM nginx:1.27-alpine
COPY default.conf /etc/nginx/conf.d/default.conf
RUN touch /var/run/nginx.pid \
    && chown -R nginx:nginx /var/cache/nginx /var/log/nginx /etc/nginx/conf.d /var/run/nginx.pid
USER nginx
EXPOSE 8080
