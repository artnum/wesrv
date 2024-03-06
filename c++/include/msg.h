#pragma once
#include <string>
#include <cstddef>

namespace wesrv {
    using namespace std;

    class Msg {
        public:
            Msg(string key, const unsigned char * date, size_t len);
            Msg(string key, string content);
            string packet();
            string getContent();
            ~Msg();
        private:
            string sign (string key);
            bool verify (string key);
            string digest;
            string content;
    };
}